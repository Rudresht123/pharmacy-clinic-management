<?php

namespace App\Console\Commands;

use App\Models\Platform\Organization;
use App\Models\Tenant\Customer;
use App\Models\Tenant\File;
use App\Services\Tenancy\TenantConnectionService;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Stand-in photographs for a demo organisation's patients.
 *
 * `php artisan demo:patient-photos --org=clinic`
 *
 * A register of a few thousand grey silhouettes reads as a database, not a
 * clinic. This fetches a hundred men's and a hundred women's portraits once —
 * the same numbered gallery the doctors' demo photographs come from — and
 * hangs them on the patients, matched to gender. Children keep the
 * placeholder: the gallery is adults, and a sixty-year-old's face on a
 * six-year-old's record is worse than none.
 *
 * One file per portrait, shared by every patient who shows it; the photo
 * endpoint only deletes a file once no patient uses it any more. Only
 * patients with no photograph are touched, so a real one is never replaced.
 *
 * Never part of `db:seed`: it writes into a real organisation's records.
 */
class SeedPatientPhotosCommand extends Command
{
    protected $signature = 'demo:patient-photos
        {--org= : Organization id or subdomain; the only one, if there is only one}
        {--min-age=16 : Younger patients keep the placeholder}';

    protected $description = "Give a demo organisation's patients stand-in photographs";

    /** Portraits per gallery in the source's numbered set. */
    private const PER_GALLERY = 100;

    public function handle(): int
    {
        $organization = $this->organization();

        if (! $organization) {
            return self::FAILURE;
        }

        $tenancy = new TenantConnectionService;
        $tenancy->connect($organization->database_name);

        try {
            $portraits = [
                Customer::MALE => $this->gallery('men'),
                Customer::FEMALE => $this->gallery('women'),
            ];

            if ($portraits[Customer::MALE] === [] || $portraits[Customer::FEMALE] === []) {
                $this->error('No portraits to hand out — the portrait service could not be reached.');

                return self::FAILURE;
            }

            $this->line('  Portraits: '.count($portraits[Customer::MALE]).' men, '.count($portraits[Customer::FEMALE]).' women');

            $this->hangOn($portraits, (int) $this->option('min-age'));
        } finally {
            $tenancy->disconnect();
        }

        return self::SUCCESS;
    }

    /**
     * One gallery's portraits, fetched once and kept as files.
     *
     * Named by gallery and number, so a second run finds them on disk and in
     * `files` rather than fetching and storing them again.
     *
     * @return list<int> file ids
     */
    private function gallery(string $gallery): array
    {
        $base = rtrim((string) config('services.demo_portraits.url'), '/');
        $timeout = (int) config('services.demo_portraits.timeout');
        $disk = Storage::disk('public');
        $ids = [];

        $bar = $this->output->createProgressBar(self::PER_GALLERY);
        $bar->setFormat("  {$gallery}  %current%/%max% [%bar%]");

        for ($n = 0; $n < self::PER_GALLERY; $n++) {
            $path = "customers/demo/{$gallery}-{$n}.jpg";

            if (! $disk->exists($path)) {
                try {
                    $response = Http::timeout($timeout)->get("{$base}/{$gallery}/{$n}.jpg");
                } catch (ConnectionException) {
                    $this->newLine();
                    $this->warn("  Could not reach {$base} — keeping the {$n} {$gallery} portraits fetched so far.");

                    break;
                }

                if (! $response->successful()) {
                    $bar->advance();

                    continue;
                }

                $disk->put($path, $response->body());
            }

            $ids[] = File::firstOrCreate(
                ['file_path' => $path, 'disk' => 'public'],
                [
                    'file_name' => "{$gallery}-{$n}.jpg",
                    'mime_type' => 'image/jpeg',
                    'file_size' => $disk->size($path),
                    'extension' => 'jpg',
                ],
            )->id;

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        return $ids;
    }

    /**
     * Give every adult without a photograph one of their gender's portraits.
     *
     * Chosen by id, so the same patient gets the same face on every run, and
     * written a portrait at a time — two hundred updates, not ten thousand,
     * and none of them writing a line of record history about a demo.
     *
     * @param  array<string, list<int>>  $portraits
     */
    private function hangOn(array $portraits, int $minAge): void
    {
        $byFile = [];
        $children = 0;
        $cutoff = Carbon::today()->subYears($minAge);

        Customer::query()
            ->whereNull('photo')
            ->whereIn('gender', [Customer::MALE, Customer::FEMALE])
            ->select(['id', 'gender', 'date_of_birth'])
            ->chunkById(2000, function ($chunk) use ($portraits, $cutoff, &$byFile, &$children) {
                foreach ($chunk as $patient) {
                    if ($patient->date_of_birth && $patient->date_of_birth->greaterThan($cutoff)) {
                        $children++;

                        continue;
                    }

                    $gallery = $portraits[$patient->gender];
                    $byFile[$gallery[$patient->id % count($gallery)]][] = $patient->id;
                }
            });

        $given = 0;

        foreach ($byFile as $fileId => $ids) {
            foreach (array_chunk($ids, 1000) as $chunk) {
                $given += Customer::query()->whereKey($chunk)->update(['photo' => $fileId]);
            }
        }

        $this->line("  Photographs: {$given} patients given one; {$children} under {$this->option('min-age')} keep the placeholder.");
    }

    private function organization(): ?Organization
    {
        $asked = $this->option('org');

        if ($asked) {
            // A subdomain is never compared against the bigint `id` — Postgres refuses the cast.
            $organization = Organization::query()
                ->when(is_numeric($asked), fn ($query) => $query->whereKey((int) $asked))
                ->when(! is_numeric($asked), fn ($query) => $query->where('subdomain', $asked))
                ->first();

            if (! $organization) {
                $this->error("No organization matches \"{$asked}\".");
            }

            return $organization;
        }

        $all = Organization::all();

        if ($all->count() === 1) {
            return $all->first();
        }

        $this->error('Name one with --org=<id|subdomain>.');

        return null;
    }
}
