<?php

namespace App\Services\Push;

use App\Models\Platform\FirebaseDevice;
use App\Models\Platform\Organization;
use App\Models\Tenant\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Which devices may receive pushes, for whom — the only thing that writes or
 * reads `firebase_devices`.
 *
 * EVERY READ NAMES THE ORGANIZATION. User ids are numbered per tenant
 * database, so user 7 exists in every organization; a lookup by user id alone
 * would reach somebody else's phone. Nothing here accepts a raw token to send
 * to, either — a push is addressed to people of one organization, and this
 * says which of their devices are live.
 */
class DeviceRegistry
{
    /**
     * Record that this person can be reached on this device.
     *
     * Keyed by the FCM token, which is unique across organizations: the same
     * token arriving from another organization (or another person) MOVES the
     * row, so the previous one stops reaching the phone. A device that
     * re-registers with a rotated token replaces its own old row.
     *
     * @param  array{device_id: string, fcm_token: string, platform: string, app_version?: string|null}  $device
     */
    public function register(
        Organization $organization,
        User $user,
        array $device,
        ?int $branchId = null,
        ?int $accessTokenId = null,
    ): FirebaseDevice {
        return DB::transaction(function () use ($organization, $user, $device, $branchId, $accessTokenId) {
            // This phone's previous token for this person is dead the moment
            // FCM hands out a new one.
            FirebaseDevice::query()
                ->where('organization_id', $organization->getKey())
                ->where('user_id', $user->getKey())
                ->where('device_id', $device['device_id'])
                ->where('fcm_token', '!=', $device['fcm_token'])
                ->delete();

            return FirebaseDevice::query()->updateOrCreate(
                ['fcm_token' => $device['fcm_token']],
                [
                    'organization_id' => $organization->getKey(),
                    'user_id' => $user->getKey(),
                    'branch_id' => $branchId,
                    'device_id' => $device['device_id'],
                    'access_token_id' => $accessTokenId,
                    'platform' => $device['platform'],
                    'app_version' => $device['app_version'] ?? null,
                    'is_active' => true,
                    'last_seen_at' => now(),
                ],
            );
        });
    }

    /** The app said goodbye for this device. */
    public function unregister(Organization $organization, User $user, string $deviceId): int
    {
        return $this->deactivate(
            FirebaseDevice::query()
                ->where('organization_id', $organization->getKey())
                ->where('user_id', $user->getKey())
                ->where('device_id', $deviceId)
        );
    }

    /**
     * The sign-in this device registered under has been revoked.
     *
     * Tied to the access token rather than the device id so that signing out
     * on the phone stops ITS pushes and nobody else's — the same rule the
     * token revocation itself follows.
     */
    public function signedOut(Organization $organization, User $user, int $accessTokenId): int
    {
        return $this->deactivate(
            FirebaseDevice::query()
                ->where('organization_id', $organization->getKey())
                ->where('user_id', $user->getKey())
                ->where('access_token_id', $accessTokenId)
        );
    }

    /** Somebody who can no longer sign in should no longer be woken up either. */
    public function forgetUser(Organization $organization, int $userId): int
    {
        return $this->deactivate(
            FirebaseDevice::query()
                ->where('organization_id', $organization->getKey())
                ->where('user_id', $userId)
        );
    }

    /**
     * The live tokens for these people, in this organization only.
     *
     * @param  list<int>  $userIds
     * @return list<string>
     */
    public function tokensFor(Organization $organization, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return FirebaseDevice::query()
            ->active()
            ->where('organization_id', $organization->getKey())
            ->whereIn('user_id', $userIds)
            ->pluck('fcm_token')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * FCM says these devices are gone — stop sending to them.
     *
     * @param  list<string>  $tokens
     */
    public function invalidate(array $tokens): int
    {
        if ($tokens === []) {
            return 0;
        }

        return $this->deactivate(FirebaseDevice::query()->whereIn('fcm_token', $tokens));
    }

    private function deactivate(Builder $query): int
    {
        return $query->where('is_active', true)->update(['is_active' => false, 'updated_at' => now()]);
    }
}
