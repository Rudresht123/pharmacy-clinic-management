<?php

use App\Models\Tenant\Doctor;
use App\Models\Tenant\EntityFieldSetting;
use App\Support\Fields\DoctorFields;
use Illuminate\Database\Migrations\Migration;

/**
 * Keeps every department already in use, now that the field is a closed list.
 *
 * `specialisation` was free text and is now chosen from options, which means
 * any value already saved that is not among the code's defaults would fail
 * validation the next time somebody opened that doctor and pressed save —
 * a field they never touched refusing a form they did not change.
 *
 * So the organization's own list starts as the defaults plus whatever it is
 * already using. A clinic that has been writing "General Physician" keeps it,
 * and can tidy the list under Settings whenever it wants to rather than
 * because a deploy forced it to.
 */
return new class extends Migration
{
    public function up(): void
    {
        $defaults = collect(DoctorFields::all())
            ->firstWhere('key', 'specialisation')['options'] ?? [];

        $inUse = Doctor::on('organization')
            ->withTrashed()
            ->whereNotNull('specialisation')
            ->where('specialisation', '<>', '')
            ->distinct()
            ->pluck('specialisation');

        $options = collect($defaults)
            ->concat($inUse->map(fn (string $value) => ['value' => $value, 'label' => $value]))
            ->unique('value')
            ->sortBy('label')
            ->values()
            ->all();

        /*
         * Only written when the organization is actually using something the
         * defaults do not cover. Otherwise the code's list stays the source,
         * and a later release that adds a department reaches this clinic
         * instead of being shadowed by a copy taken today.
         */
        if (count($options) === count($defaults)) {
            return;
        }

        EntityFieldSetting::on('organization')->updateOrCreate(
            [
                'entity' => EntityFieldSetting::ENTITY_DOCTOR,
                'field_key' => 'specialisation',
            ],
            ['options' => $options],
        );
    }

    public function down(): void
    {
        // The row is the organization's own setting from here on; removing it
        // would throw away departments somebody may since have added by hand.
    }
};
