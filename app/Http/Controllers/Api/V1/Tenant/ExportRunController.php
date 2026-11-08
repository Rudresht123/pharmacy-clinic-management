<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Resources\Tenant\ExportRunResource;
use App\Jobs\RunExport;
use App\Models\Platform\Organization;
use App\Models\Tenant\ExportRun;
use App\Models\Tenant\User;
use App\Services\Exports\ExportPlanner;
use App\Services\Exports\ExportWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Export Center files made on the queue, so a long register never has to fit
 * in one web request — and the person keeps working while it is written.
 *
 * Asking for one checks everything the direct download checks (ExportPlanner)
 * and answers at once with the run; the tray then watches it. A run is its
 * maker's own: nobody else lists it, watches it or downloads it, whatever
 * their role — a file of patient records goes to the person who asked.
 *
 * @group Tenant > Export center
 */
class ExportRunController extends BaseApiController
{
    public function __construct(
        private readonly ExportPlanner $planner,
    ) {}

    /**
     * The tray: this person's runs from the last day, newest first — the
     * ones still being made and the files waiting to be taken.
     */
    public function index(Request $request): JsonResponse
    {
        [, $user] = $this->caller($request);

        $runs = ExportRun::query()
            ->where('user_id', $user->id)
            ->whereNull('dismissed_at')
            ->where(fn ($query) => $query
                ->whereIn('status', ExportRun::ACTIVE)
                ->orWhere('created_at', '>=', Carbon::now()->subHours(ExportRun::KEEP_HOURS)))
            ->latest('id')
            ->limit(20)
            ->get();

        return $this->ok(ExportRunResource::collection($runs));
    }

    /**
     * Start making one export's file: every row the filters match, in the
     * columns chosen. Refused at once when the person may not have it, when
     * nothing matches, or when they already have three being made.
     */
    public function store(Request $request, string $dataset): JsonResponse
    {
        $input = $request->validate([
            'format' => ['required', Rule::in(ExportWriter::FORMATS)],
            ...ExportPlanner::rules(),
        ]);

        [$organization, $user] = $this->caller($request);

        [$found, $filters] = $this->planner->plan($organization, $user, $dataset, $input);

        $total = (clone $found->rows($filters))->count();

        if ($total === 0) {
            throw ValidationException::withMessages(['filters' => 'Nothing matches these filters, so there is nothing to export.']);
        }

        if (ExportRun::query()->where('user_id', $user->id)->active()->count() >= ExportRun::MAX_ACTIVE) {
            throw new ConflictHttpException('You already have '.ExportRun::MAX_ACTIVE.' exports being prepared. Wait for one to finish, or cancel one.');
        }

        $heading = $this->planner->heading($found, $filters);

        $run = ExportRun::query()->create([
            'user_id' => $user->id,
            'dataset' => $found->key(),
            'format' => $input['format'],
            'title' => $heading['title'],
            'noun' => $found->noun(),
            'filters' => ExportPlanner::keep($input),
            'status' => ExportRun::QUEUED,
            'total_rows' => $total,
        ]);

        RunExport::queue($organization->id, $run->id);

        return $this->created(new ExportRunResource($run->refresh()), 'Your export is being prepared.');
    }

    public function show(Request $request, int $run): JsonResponse
    {
        return $this->ok(new ExportRunResource($this->own($request, $run)));
    }

    /** The finished file, while it is kept. */
    public function file(Request $request, int $run): StreamedResponse
    {
        $found = $this->own($request, $run);

        abort_unless($found->isReady() && $found->file_path !== null, 410, 'This file is no longer kept. Export it again.');

        $disk = Storage::disk(ExportRun::DISK);

        abort_unless($disk->exists($found->file_path), 410, 'This file is no longer kept. Export it again.');

        $found->forceFill(['downloaded_at' => Carbon::now()])->save();

        return $disk->download($found->file_path, $found->file_name ?? basename($found->file_path), [
            'Content-Type' => ExportWriter::mime($found->format),
        ]);
    }

    /** Stop one still being made; the job stops at its next row. */
    public function cancel(Request $request, int $run): JsonResponse
    {
        $found = $this->own($request, $run);

        if (! $found->isActive()) {
            throw new ConflictHttpException('This export has already finished.');
        }

        $found->forceFill(['status' => ExportRun::CANCELLED, 'finished_at' => Carbon::now()])->save();

        return $this->ok(new ExportRunResource($found), 'Export cancelled.');
    }

    /**
     * Off the tray. A finished file is deleted with it; the run itself stays
     * on record — who exported what, and when.
     */
    public function dismiss(Request $request, int $run): JsonResponse
    {
        $found = $this->own($request, $run);

        if ($found->isActive()) {
            throw new ConflictHttpException('Cancel this export before clearing it.');
        }

        if ($found->file_path !== null) {
            Storage::disk(ExportRun::DISK)->delete($found->file_path);
        }

        $found->forceFill([
            'dismissed_at' => Carbon::now(),
            // A finished file just deleted reads as expired, like one the daily sweep took.
            'status' => $found->status === ExportRun::COMPLETED ? ExportRun::EXPIRED : $found->status,
            'file_path' => null,
        ])->save();

        return $this->ok(null, 'Cleared.');
    }

    /* ------------------------------------------------------------------ */

    /** A run of this person's — anyone else's reads as not there. */
    private function own(Request $request, int $id): ExportRun
    {
        [, $user] = $this->caller($request);

        $run = ExportRun::query()->whereKey($id)->where('user_id', $user->id)->first();

        abort_if($run === null, 404, 'There is no such export.');

        return $run;
    }

    /** @return array{0: Organization, 1: User} */
    private function caller(Request $request): array
    {
        $organization = $request->attributes->get('tenant.organization');
        $user = $request->user();

        abort_unless($organization instanceof Organization && $user instanceof User, 403);

        return [$organization, $user];
    }
}
