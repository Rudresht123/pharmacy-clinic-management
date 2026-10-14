<?php

namespace App\Services\Geo;

use App\Models\Platform\Organization;
use App\Models\Platform\OrganizationProfile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * Where an organization is: its address on the profile, and the point on the
 * map that address means.
 *
 * The forms send a pin — found from the address while the person typed, or
 * dragged by them onto the building — and that pin is kept as sent. A save
 * with no pin (an older client, the phone) has its address looked up once the
 * response has gone, so nobody waits on OpenStreetMap to save a form.
 */
class OrganizationLocator
{
    /** The fields an address form may send for the profile. */
    public const FIELDS = ['postal_code', 'city', 'state_province', 'latitude', 'longitude', 'location_source'];

    public function __construct(
        private readonly Geocoder $geocoder,
    ) {}

    /**
     * Validation rules for the location fields, shared by every form that
     * saves an organization's address.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'location_source' => ['nullable', 'string', 'in:'.implode(',', OrganizationProfile::SOURCES)],
        ];
    }

    /**
     * Save the address fields a form sent, with its pin if it sent one.
     *
     * @param  array<string, mixed>  $fields
     */
    public function save(Organization $organization, array $fields): void
    {
        $fields = Arr::only($fields, self::FIELDS);

        if ($fields === []) {
            return;
        }

        $profile = $organization->profile()->firstOrNew();
        $profile->fill(Arr::only($fields, ['postal_code', 'city', 'state_province']));

        if (isset($fields['latitude'], $fields['longitude'])) {
            $profile->fill([
                'latitude' => (float) $fields['latitude'],
                'longitude' => (float) $fields['longitude'],
                'location_source' => $fields['location_source'] ?? OrganizationProfile::SOURCE_MANUAL,
                'located_at' => now(),
            ]);

            $profile->save();

            return;
        }

        $profile->save();

        // No pin: find one from the address, after the person has their answer.
        $id = $organization->id;

        dispatch(fn () => app(self::class)->locate(Organization::findOrFail($id)))->afterResponse();
    }

    /**
     * Look the saved address up and store the point it means.
     *
     * A pin somebody placed by hand is left alone unless `$force` says
     * otherwise — a lookup is a guess, and theirs was not. False when there
     * is no address or nothing was found; an outage is logged, not thrown.
     */
    public function locate(Organization $organization, bool $force = false): bool
    {
        $profile = $organization->profile;

        if (! $profile) {
            return false;
        }

        if (! $force && $profile->location_source === OrganizationProfile::SOURCE_MANUAL && $profile->hasCoordinates()) {
            return true;
        }

        try {
            $point = $this->geocoder->locate(
                $organization->address,
                $profile->city,
                $profile->state_province,
                $profile->postal_code,
            );
        } catch (GeocodingUnavailable $exception) {
            Log::warning("Could not locate organization {$organization->id}: {$exception->getMessage()}");

            return false;
        }

        if (! $point) {
            return false;
        }

        $profile->update([
            'latitude' => $point['latitude'],
            'longitude' => $point['longitude'],
            'location_source' => $point['source'],
            'located_at' => now(),
        ]);

        return true;
    }
}
