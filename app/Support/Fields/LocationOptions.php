<?php

namespace App\Support\Fields;

use App\Models\Tenant\Location;

/**
 * The organization's branches, as select options.
 *
 * The one registry input that comes from the database rather than from code
 * — a branch list cannot be hardcoded. Shared because more than one screen
 * asks the same question: which branch registered this customer, and which
 * branch does this person work at.
 */
class LocationOptions
{
    /**
     * @return list<array{value: string, label: string}>
     */
    public static function all(): array
    {
        try {
            return Location::on('organization')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Location $location) => [
                    'value' => (string) $location->id,
                    'label' => $location->name,
                ])
                ->all();
        } catch (\Throwable) {
            // A settings screen should still render if this cannot be read.
            return [];
        }
    }
}
