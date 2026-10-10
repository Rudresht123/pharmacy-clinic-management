<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Platform\FirebaseDevice;
use App\Models\Tenant\PersonalAccessToken;
use App\Models\Tenant\User;
use App\Services\Push\DeviceRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * "This phone can receive pushes for me."
 *
 * The same endpoint for staff and for patients — both are tenant users on the
 * token guard — mounted in both route files. Who the device belongs to is
 * never taken from the body: it is whoever is signed in, in the organization
 * they signed in to. The body says only which device and which FCM token.
 *
 * Registering again is normal and cheap: the app calls this after every
 * sign-in and whenever Firebase rotates the token.
 *
 * @group Tenant > Devices & Push
 */
class DeviceController extends BaseApiController
{
    public function __construct(
        private readonly DeviceRegistry $devices,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_id' => ['required', 'string', 'max:191'],
            'fcm_token' => ['required', 'string', 'max:512'],
            'platform' => ['required', 'string', Rule::in(FirebaseDevice::PLATFORMS)],
            'app_version' => ['nullable', 'string', 'max:40'],
        ]);

        $organization = $request->attributes->get('tenant.organization');
        $user = $this->actor();

        if ($organization === null || $user === null) {
            return $this->fail('Unauthenticated.', 401);
        }

        $branch = $request->attributes->get('tenant.branch');

        $device = $this->devices->register(
            $organization,
            $user,
            $data,
            $branch === null ? null : (int) $branch,
            $this->accessTokenId($user),
        );

        return $this->ok([
            'device_id' => $device->device_id,
            'platform' => $device->platform,
            'is_active' => $device->is_active,
        ], 'Device registered.');
    }

    public function destroy(Request $request, string $deviceId): JsonResponse
    {
        $organization = $request->attributes->get('tenant.organization');
        $user = $this->actor();

        if ($organization !== null && $user !== null) {
            $this->devices->unregister($organization, $user, $deviceId);
        }

        // The same answer whether or not there was a device by that id: it is
        // not a question anybody needs answered about someone else's phone.
        return $this->noContent('Device removed.');
    }

    /**
     * The signed-in tenant user. `tenant.actor` puts a token user on the `web`
     * guard, so one guard answers for browsers and phones alike.
     */
    private function actor(): ?User
    {
        $user = Auth::guard('web')->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * The sign-in this registration belongs to, when it is a token — so that
     * signing out on this phone switches off this phone's pushes.
     */
    private function accessTokenId(User $user): ?int
    {
        $token = $user->currentAccessToken();

        return $token instanceof PersonalAccessToken ? (int) $token->getKey() : null;
    }
}
