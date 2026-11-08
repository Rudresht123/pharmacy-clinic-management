<?php

namespace App\Jobs;

use App\Models\Platform\Organization;
use App\Models\Tenant\ExportRun;
use App\Models\Tenant\User;
use App\Services\Exports\ExportCancelled;
use App\Services\Exports\ExportPlanner;
use App\Services\Exports\ExportWriter;
use App\Services\Tenancy\TenantConnectionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Writes one Export Center file in the background (App\Models\Tenant\ExportRun).
 *
 * The person who asked keeps working; the screen watches the run's row, which
 * this job moves along — how many rows are written, then where the file is.
 *
 * CONNECTS ITS OWN TENANT, like every job here: a worker serves many clinics
 * in one process, and assuming the connection already points anywhere is how
 * one clinic's records end up in another's file.
 *
 * ASKS AGAIN WHO MAY HAVE IT. The filters were checked when the file was
 * asked for, but a role can change while it waits in the queue; the file
 * obeys the role as it is when written.
 *
 * Once only: a failed export is asked for again from the screen, not retried
 * here — a second attempt would start the whole file over without the person
 * knowing why it went back to zero.
 */
class RunExport implements ShouldQueue
{
    use Queueable;

    /** How often the row's progress is written, at most — the screen polls about this often. */
    private const PROGRESS_EVERY = 1.0;

    public int $tries = 1;

    /** A register of a hundred thousand rows takes minutes, not half an hour. */
    public int $timeout = 1800;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $organizationId,
        public readonly int $runId,
    ) {}

    /** Onto the export queue — or straight through where the app runs its queue inline (tests, a bare install). */
    public static function queue(int $organizationId, int $runId): void
    {
        $sync = config('queue.default') === 'sync';

        dispatch(new self($organizationId, $runId))
            ->onConnection($sync ? 'sync' : 'exports')
            ->onQueue('exports');
    }

    public function handle(TenantConnectionService $tenants, ExportPlanner $planner, ExportWriter $writer): void
    {
        $organization = Organization::query()->find($this->organizationId);

        if ($organization === null || $organization->database_name === null) {
            Log::warning('[exports] organization missing, dropping the run', [
                'organization_id' => $this->organizationId,
                'run_id' => $this->runId,
            ]);

            return;
        }

        $tenants->run($organization->database_name, fn () => $this->write($organization, $planner, $writer));
    }

    private function write(Organization $organization, ExportPlanner $planner, ExportWriter $writer): void
    {
        $run = ExportRun::query()->find($this->runId);

        // Cancelled while it waited, or already taken by another worker.
        if ($run === null || $run->status !== ExportRun::QUEUED) {
            return;
        }

        $run->forceFill(['status' => ExportRun::RUNNING, 'started_at' => Carbon::now(), 'processed_rows' => 0])->save();

        $path = null;

        try {
            $user = User::query()->find($run->user_id);

            if ($user === null) {
                $this->fail($run, 'The person who asked for this export no longer has an account.');

                return;
            }

            [$dataset, $filters, $columns] = $planner->plan($organization, $user, $run->dataset, $run->filters);

            $query = $dataset->rows($filters);
            $total = (clone $query)->count();

            // The count as it is now; the screen counted when it was asked for.
            $run->forceFill(['total_rows' => $total])->save();

            $name = str_replace('.', '-', $dataset->key());
            $path = 'exports/'.$this->organizationId.'/'.$run->id.'-'.Str::random(24).'.'.$run->format;
            $disk = Storage::disk(ExportRun::DISK);
            $disk->makeDirectory(dirname($path));

            $last = microtime(true);

            $writer->toFile(
                $run->format,
                $disk->path($path),
                $columns,
                $planner->ordered($dataset, $query, $run->filters),
                fn (Model $record) => $dataset->row($record),
                $total,
                $planner->heading($dataset, $filters),
                muted: fn (Model $record) => $dataset->muted($record),
                tick: function (int $done) use ($run, &$last) {
                    if (microtime(true) - $last < self::PROGRESS_EVERY) {
                        return;
                    }

                    $last = microtime(true);
                    $this->progress($run, $done);
                },
            );

            // Cancelled after its last row was read: the person said no, so no file.
            if (ExportRun::query()->whereKey($run->id)->value('status') === ExportRun::CANCELLED) {
                throw new ExportCancelled;
            }

            $run->forceFill([
                'status' => ExportRun::COMPLETED,
                'processed_rows' => $run->format === 'pdf' ? min($total, ExportWriter::PDF_LIMIT) : $total,
                'file_name' => $writer->fileName($name, $run->format),
                'file_path' => $path,
                'file_size' => $disk->size($path),
                'finished_at' => Carbon::now(),
                'expires_at' => Carbon::now()->addHours(ExportRun::KEEP_HOURS),
            ])->save();
        } catch (ExportCancelled) {
            $this->discard($path);
            // The row already says cancelled; only when it stopped is added.
            $run->forceFill(['finished_at' => Carbon::now()])->save();
        } catch (HttpExceptionInterface $refused) {
            $this->discard($path);
            $this->fail($run, $refused->getMessage() ?: 'You may no longer export these records.');
        } catch (ValidationException $invalid) {
            $this->discard($path);
            $this->fail($run, collect($invalid->errors())->flatten()->first() ?? 'These filters are no longer valid.');
        } catch (Throwable $error) {
            $this->discard($path);
            $this->fail($run, 'The file could not be written. Try again, or narrow the dates.');

            // Logged, not thrown: the run's row already says it failed and why.
            report($error);
        }
    }

    /**
     * How far it has got — written straight to the row, never through the
     * model, so a cancel made on the screen meanwhile is not written back over.
     * And the place to stop when it was.
     */
    private function progress(ExportRun $run, int $done): void
    {
        ExportRun::query()->whereKey($run->id)->where('status', ExportRun::RUNNING)->update(['processed_rows' => $done]);

        if (ExportRun::query()->whereKey($run->id)->value('status') === ExportRun::CANCELLED) {
            throw new ExportCancelled;
        }
    }

    private function fail(ExportRun $run, string $reason): void
    {
        $run->forceFill([
            'status' => ExportRun::FAILED,
            'error' => mb_substr($reason, 0, 500),
            'finished_at' => Carbon::now(),
        ])->save();
    }

    private function discard(?string $path): void
    {
        if ($path !== null) {
            Storage::disk(ExportRun::DISK)->delete($path);
        }
    }

    /**
     * The worker gave up on it — a timeout, or the process killed. Marked
     * failed here, because a run left at "running" forever is a progress bar
     * that never moves.
     */
    public function failed(?Throwable $exception): void
    {
        try {
            $organization = Organization::query()->find($this->organizationId);

            if ($organization?->database_name === null) {
                return;
            }

            app(TenantConnectionService::class)->run($organization->database_name, function () {
                ExportRun::query()
                    ->whereKey($this->runId)
                    ->whereIn('status', ExportRun::ACTIVE)
                    ->update([
                        'status' => ExportRun::FAILED,
                        'error' => 'The file took too long or the export stopped. Try again, or narrow the dates.',
                        'finished_at' => Carbon::now(),
                    ]);
            });
        } catch (Throwable $inner) {
            Log::error('[exports] could not record a failed run', ['run_id' => $this->runId, 'detail' => $inner->getMessage()]);
        }
    }
}
