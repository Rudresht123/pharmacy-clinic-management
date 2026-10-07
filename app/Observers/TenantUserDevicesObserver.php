<?php

namespace App\Observers;

use App\Models\Tenant\User;
use App\Services\Push\DeviceRegistry;
use App\Services\Tenancy\CurrentTenant;

/**
 * Somebody who can no longer sign in should no longer be woken up either.
 *
 * Deactivating a member of staff, removing them, or removing a patient's
 * login stops their phones receiving this organization's pushes — at once,
 * however it happened (the People screen, a seeder, a console command),
 * rather than whenever FCM next reports the device gone.
 *
 * The devices live in the master database and are keyed by organization, so
 * the organization is read from the connection the user was saved on.
 */
class TenantUserDevicesObserver
{
    public function __construct(
        private readonly DeviceRegistry $devices,
    ) {}

    public function updated(User $user): void
    {
        if ($user->wasChanged('is_active') && ! $user->is_active) {
            $this->forget($user);
        }
    }

    public function deleted(User $user): void
    {
        $this->forget($user);
    }

    private function forget(User $user): void
    {
        $organization = CurrentTenant::organization();

        if ($organization !== null) {
            $this->devices->forgetUser($organization, (int) $user->getKey());
        }
    }
}
