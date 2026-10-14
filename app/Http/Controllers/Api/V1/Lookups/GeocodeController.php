<?php

namespace App\Http\Controllers\Api\V1\Lookups;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Services\Geo\Geocoder;
use App\Services\Geo\GeocodingUnavailable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * An address to a point on the map — what an address form calls to drop its
 * pin before anything is saved. A fact about the world rather than about any
 * organization, so it sits beside the PIN code lookup on both the platform's
 * and the workspace's routes.
 *
 * @group Lookups
 */
class GeocodeController extends BaseApiController
{
    public function show(Request $request, Geocoder $geocoder): JsonResponse
    {
        $data = $request->validate([
            'address' => ['nullable', 'string', 'max:1000'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'pincode' => ['nullable', 'string', 'max:10'],
        ]);

        if (! array_filter($data)) {
            return $this->fail('Give an address, a city or a PIN code to look up.', 422);
        }

        try {
            $point = $geocoder->locate(
                $data['address'] ?? null,
                $data['city'] ?? null,
                $data['state'] ?? null,
                $data['pincode'] ?? null,
            );
        } catch (GeocodingUnavailable) {
            return $this->fail('The map service could not be reached. Place the pin by hand.', 503);
        }

        if (! $point) {
            return $this->fail('That address could not be found on the map. Place the pin by hand.', 404);
        }

        return $this->ok($point);
    }
}
