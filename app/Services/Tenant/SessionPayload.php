<?php

namespace App\Services\Tenant;

use App\Http\Resources\Tenant\OrganizationSummaryResource;
use App\Http\Resources\Tenant\UserResource;
use App\Models\Platform\Organization;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\User;
use App\Services\Permissions\Permission;

/**
 * Everything a client needs to know about a signed-in session.
 *
 * ONE place, used by every way in: the browser's login, `me`, the staff
 * token and the patient's one-time code. They described the same session in
 * separate methods once and drifted — login answered without `modules` or
 * `capabilities`, so a freshly signed-in person saw a menu built from an empty
 * list until something forced `me` to run. A second sign-in path is exactly
 * how that would happen again, so it asks here too.
 */
class SessionPayload
{
    public function __construct(
        private readonly Permission $permission,
        private readonly OrganizationSetup $setup,
    ) {}

    /** @return array<string, mixed> */
    public function for(User $user, Organization $organization): array
    {
        $user->loadMissing(['memberships.location', 'memberships.role']);

        $branch = $this->permission->branchFor($user);

        return [
            'user' => UserResource::make($user),
            'organization' => OrganizationSummaryResource::make($organization),

            /*
             * Where this person may work, and which of those the answers below
             * are about. The client's branch switcher renders from this and
             * sends the choice back as X-Branch-Id — which ResolveActingBranch
             * checks against these same memberships rather than trusting.
             */
            'branches' => $user->memberships
                ->map(fn ($membership) => [
                    'id' => $membership->location_id,
                    'name' => $membership->location?->name,
                    'role' => $membership->role?->name,
                    'is_primary' => $membership->is_primary,
                ])
                ->values(),

            'active_branch' => $branch,

            /*
             * Which doctor this account belongs to, when it belongs to one.
             *
             * A doctor may have no login at all — a visiting consultant who
             * never touches the system is why `doctors` is its own table — so
             * this is null for almost everybody. Where it is set, the queue
             * opens on their own list instead of the whole department.
             */
            'doctor_id' => $this->linked($user, Doctor::class),

            /*
             * Which patient record this account belongs to, when it is a
             * patient's own login.
             *
             * Identity, not permission — the same kind of fact as `doctor_id`.
             * What the patient may DO still comes from `capabilities` below,
             * from a role the owner can edit; this only says whose data the
             * portal screens are about.
             */
            'customer_id' => $this->linked($user, Customer::class),

            /*
             * What is running where this person works: sold to the
             * organization, and switched on at their branch. The menu hides
             * what is not here and EnsureTenantHasModule refuses it, from this
             * same service — the menu and the API agree by construction.
             */
            'modules' => $this->permission->modulesAt($organization, $branch),

            /*
             * This person's own capabilities, NOT the organization's pool.
             * Answering with the pool would have every client believing a
             * member of staff could do everything the organization was sold.
             */
            'capabilities' => $this->permission->capabilitiesFor($organization, $user),

            /*
             * Whether the owner has finished organisation setup. Until they
             * have, the client keeps the owner on the setup screen and tells
             * everybody else to wait — the workspace is not usable half set up.
             */
            'setup_completed' => $this->setup->isComplete(),
        ];
    }

    /**
     * The session, plus a fresh token for a device.
     *
     * One token per device name, replaced on each sign-in. Without this a phone
     * that signs in, is signed out by an expiry, and signs in again leaves a
     * live token behind every time — and the list of signed-in devices fills
     * with entries nobody can account for.
     *
     * @return array<string, mixed>
     */
    public function withToken(User $user, Organization $organization, ?string $device, ?string $ip): array
    {
        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $ip,
        ])->save();

        $device = trim((string) $device) ?: 'Mobile app';

        $user->tokens()->where('name', $device)->delete();

        return $this->for($user, $organization) + [
            'token' => $user->createToken($device)->plainTextToken,
        ];
    }

    /** The id of the record this login is linked to, when it is of that type. */
    private function linked(User $user, string $type): ?int
    {
        return $user->userable_type === $type ? (int) $user->userable_id : null;
    }
}
