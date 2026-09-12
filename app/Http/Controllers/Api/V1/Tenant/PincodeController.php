<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Services\Geo\PincodeLookup;
use App\Services\Geo\PincodeLookupUnavailable;
use Illuminate\Http\JsonResponse;

/**
 * The place an Indian PIN code belongs to: country, state, district, and the
 * post-office areas it covers.
 *
 * Any form with an address can ask — patient registration today, a branch or
 * a supplier tomorrow — which is why it lives under `lookups` rather than
 * under the customers it was first written for. Signed-in users only, so it
 * is not an open relay to India Post for anybody on the internet.
 */
class PincodeController extends BaseApiController
{
    public function show(string $pincode, PincodeLookup $lookup): JsonResponse
    {
        try {
            $place = $lookup->find($pincode);
        } catch (PincodeLookupUnavailable) {
            // Said plainly, because the form can carry on without it: the
            // address fields are still there to fill by hand.
            return $this->respond(
                message: 'The PIN code service is not answering right now. Please fill in the address by hand.',
                status: 503,
            );
        }

        if ($place === null) {
            return $this->respond(message: 'No post office has this PIN code.', status: 404);
        }

        return $this->ok($place);
    }
}
