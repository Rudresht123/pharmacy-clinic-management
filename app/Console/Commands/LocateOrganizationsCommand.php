<?php

namespace App\Console\Commands;

use App\Models\Platform\Organization;
use App\Services\Geo\OrganizationLocator;
use Illuminate\Console\Command;

/**
 * Put organizations that already have an address on the map.
 *
 * New saves locate themselves; this is for the ones saved before the map
 * existed, or after an outage. A hand-placed pin is never moved — not even
 * with --force, which only re-asks for points that came from a lookup.
 */
class LocateOrganizationsCommand extends Command
{
    protected $signature = 'organizations:locate
        {--force : Look up again even organizations that already have a point (hand-placed pins are kept)}';

    protected $description = 'Find map coordinates for organizations from their saved address';

    public function handle(OrganizationLocator $locator): int
    {
        $organizations = Organization::query()
            ->whereHas('profile', function ($query) {
                $query->where(fn ($q) => $q->whereNotNull('postal_code')->orWhereNotNull('city'));

                if (! $this->option('force')) {
                    $query->whereNull('latitude');
                }
            })
            ->with('profile')
            ->get();

        if ($organizations->isEmpty()) {
            $this->info('Nothing to locate.');

            return self::SUCCESS;
        }

        $found = 0;

        foreach ($organizations as $organization) {
            $located = $locator->locate($organization);
            $source = $organization->profile->fresh()?->location_source;

            $this->line(sprintf(
                '  %s  %s%s',
                $located ? '<info>✓</info>' : '<comment>✗</comment>',
                $organization->organization_name,
                $located ? " ({$source})" : ' — not found',
            ));

            $found += $located ? 1 : 0;
        }

        $this->newLine();
        $this->info("Located {$found} of {$organizations->count()}.");

        return self::SUCCESS;
    }
}
