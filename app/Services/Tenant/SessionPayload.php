<?php

namespace App\Services\Tenant;

use App\Http\Resources\Tenant\OrganizationSummaryResource;
use App\Http\Resources\Tenant\UserResource;
use App\Models\Platform\Organization;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\User;
use App\Services\Modules\FeatureResolver;
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
        private readonly FeatureResolver $features,
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
            'modules' => $modules = $this->permission->modulesAt($organization, $branch),

            /*
             * The same answer as `modules`, as an on/off map — every module
             * the software has, plus the features made of more than one
             * (`opd_pharmacy_handoff`). A client asks `features.pharmacy`
             * rather than searching a list, and a pharmacy-only business reads
             * the handoff as false instead of having to know which two modules
             * it needs. Laravel is the source of truth for this, never the
             * client and never Firebase.
             */
            'features' => $this->permission->featuresAt($organization, $branch),

            /*
             * WhatsApp, SMS and email live inside the communication module, so
             * they are reported under it: usable where the module runs here
             * AND the clinic has connected an account for the channel.
             */
            'communication' => $this->features->communicationFor($modules),

            /*
             * How to reach Reverb, when it is running. Which CHANNELS to join
             * is not here: each screen is handed its own channel name by the
             * endpoint that loads it, from the server that will later
             * authorize the subscription — a client never builds one.
             */
            'realtime' => $this->realtime(),

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

    /**
     * Connection details for Reverb, or `enabled: false`.
     *
     * Disabled whenever broadcasting is not Reverb or has no key — a client
     * told so falls back to re-fetching, rather than opening a socket to
     * nowhere and showing a counter that never updates. The key is the public
     * half; the secret never leaves the server.
     *
     * @return array<string, mixed>
     */
    private function realtime(): array
    {
        $connection = (array) config('broadcasting.connections.reverb', []);

        if (config('broadcasting.default') !== 'reverb' || blank($connection['key'] ?? null)) {
            return ['enabled' => false];
        }

        return [
            'enabled' => true,
            'driver' => 'reverb',
            'key' => $connection['key'],
            'host' => $connection['client']['host'] ?? null,
            'port' => $connection['client']['port'] ?? null,
            'scheme' => $connection['client']['scheme'] ?? 'https',
            'auth_endpoint' => '/api/v1/broadcasting/auth',
        ];
    }

    /** The id of the record this login is linked to, when it is of that type. */
    private function linked(User $user, string $type): ?int
    {
        return $user->userable_type === $type ? (int) $user->userable_id : null;
    }
}
