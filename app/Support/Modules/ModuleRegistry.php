<?php

namespace App\Support\Modules;

/**
 * Every module the software has, and what each one lets somebody do.
 *
 * Code is authoritative here, the same way App\Support\Fields\FieldRegistry
 * is authoritative about fields: a module's capabilities are a property of
 * the code that implements them, not data an administrator can invent. The
 * `modules` table is a synced catalogue of this list — it exists so bindings
 * can point at a row, and so a module can be retired platform-wide — and
 * `organization_modules` stores only which organization has which.
 *
 * The capabilities are the point, not decoration. Permission is decided at
 * three levels, and this list is the vocabulary all three speak:
 *
 *   1. The super admin sells a module to an organization
 *      (`organization_modules`, master database).
 *   2. The owner decides which branches use it
 *      (`location_modules`, tenant database).
 *   3. The owner puts capabilities on roles, and roles on staff
 *      (`roles` / `role_capabilities`, tenant database).
 *
 * App\Services\Permissions\Permission asks them in that order. The pool an
 * owner may distribute at level 3 is exactly the capabilities of the modules
 * won at level 1 — an unsold module's capabilities do not appear on the role
 * screen at all, rather than appearing and being denied.
 *
 * Capability keys are prefixed with their module's key and are never reused
 * across modules — a permission has to say which module granted it, or
 * revoking a module cannot know what to take away.
 */
class ModuleRegistry
{
    public const GROUP_FOUNDATION = 'foundation';

    public const GROUP_PHARMACY = 'pharmacy';

    public const GROUP_CLINICAL = 'clinical';

    public const GROUP_COMMERCE = 'commerce';

    /**
     * Where a capability can mean anything.
     *
     * `settings.manage` changes what every branch calls a patient — there is
     * no version of it that applies at one branch and not another, so putting
     * it on a branch role would be offering a choice the software cannot
     * honour. `customers.view` is the opposite: it means "here", and a
     * receptionist holding it at Lucknow has no business reading Delhi's book.
     *
     * The role screen filters by this, so an organization-scoped capability is
     * never offered on a branch role — not shown greyed out, not shown at all.
     */
    public const SCOPE_ORGANIZATION = 'organization';

    public const SCOPE_BRANCH = 'branch';

    /** Both, in the order a role screen should show them. */
    public const SCOPES = [self::SCOPE_ORGANIZATION, self::SCOPE_BRANCH];

    /**
     * @return list<array{
     *     key: string,
     *     name: string,
     *     description: string,
     *     icon: string,
     *     group: string,
     *     is_core: bool,
     *     capabilities: list<array{key: string, name: string}>,
     * }>
     */
    public static function all(): array
    {
        return [
            /*
            |------------------------------------------------------------
            | Core — every organization has these, always.
            |------------------------------------------------------------
            |
            | Marked is_core so the binding screen cannot take them away.
            | An organization with no branches and no people is not a
            | cheaper organization, it is a broken one, and a super admin
            | should not be able to produce that state by mistake.
            */
            [
                'key' => 'branches',
                'name' => 'Branches',
                'description' => 'The stores, warehouses and sites an organization operates.',
                'icon' => 'ti ti-building-store',
                'group' => self::GROUP_FOUNDATION,
                'is_core' => true,
                /*
                 * Creating, editing and removing are three capabilities, not
                 * one. "Can add a branch but not delete one" is an ordinary
                 * thing to want, and a single `manage` could not express it —
                 * an owner delegating any of the three had to delegate all
                 * three. The same split runs through every module below.
                 */
                'capabilities' => [
                    ['key' => 'branches.view', 'name' => 'View branches', 'scope' => self::SCOPE_BRANCH],
                    ['key' => 'branches.create', 'name' => 'Add branches', 'scope' => self::SCOPE_BRANCH],
                    ['key' => 'branches.edit', 'name' => 'Edit branches', 'scope' => self::SCOPE_BRANCH],
                    ['key' => 'branches.delete', 'name' => 'Remove branches', 'scope' => self::SCOPE_BRANCH],
                ],
            ],
            [
                'key' => 'people',
                'name' => 'People',
                'description' => 'The staff who sign in, and the branch each one works at.',
                'icon' => 'ti ti-users-group',
                'group' => self::GROUP_FOUNDATION,
                'is_core' => true,
                'capabilities' => [
                    ['key' => 'people.view', 'name' => 'View staff', 'scope' => self::SCOPE_BRANCH],
                    ['key' => 'people.create', 'name' => 'Add staff', 'scope' => self::SCOPE_BRANCH],
                    ['key' => 'people.edit', 'name' => 'Edit staff', 'scope' => self::SCOPE_BRANCH],
                    ['key' => 'people.delete', 'name' => 'Remove staff', 'scope' => self::SCOPE_BRANCH],

                    /*
                     * The four above reach the holder's own branch. This one
                     * lifts that, and is what organization HR holds rather
                     * than a branch manager.
                     *
                     * A capability rather than something inferred from having
                     * no branch: administering the whole network is a thing an
                     * owner decides to grant, not a side effect of a column
                     * being null.
                     */
                    ['key' => 'people.across_branches', 'name' => 'Manage staff at every branch', 'scope' => self::SCOPE_ORGANIZATION],

                    /*
                     * Held apart from the four above, and organization-scoped
                     * on purpose: moving somebody between branches is what
                     * makes cross-branch reach transitive. A branch manager
                     * who could do it would only have to move themselves.
                     */
                    ['key' => 'people.assign_branch', 'name' => 'Assign staff to branches', 'scope' => self::SCOPE_ORGANIZATION],

                    /*
                     * Lets a branch write its OWN roles, from the modules that
                     * branch was given. Branch-scoped, so a manager holding it
                     * at Lucknow writes Lucknow's roles and nobody else's.
                     *
                     * The owner never needs it — they bypass roles entirely —
                     * and it cannot widen anything: the pool a branch may draw
                     * from is what the organization switched on there.
                     */
                    ['key' => 'people.roles', 'name' => 'Write roles for this branch', 'scope' => self::SCOPE_BRANCH],
                ],
            ],
            [
                'key' => 'customers',
                'name' => 'Customers & Patients',
                'description' => 'One record per person served, shared by every branch.',
                'icon' => 'ti ti-users',
                'group' => self::GROUP_FOUNDATION,
                'is_core' => true,
                'capabilities' => [
                    ['key' => 'customers.view', 'name' => 'View customers', 'scope' => self::SCOPE_BRANCH],
                    ['key' => 'customers.create', 'name' => 'Add customers', 'scope' => self::SCOPE_BRANCH],
                    ['key' => 'customers.edit', 'name' => 'Edit customers', 'scope' => self::SCOPE_BRANCH],
                    ['key' => 'customers.delete', 'name' => 'Remove customers', 'scope' => self::SCOPE_BRANCH],
                ],
            ],
            [
                'key' => 'settings',
                'name' => 'Configuration',
                'description' => 'Field settings and what the organization calls each record.',
                'icon' => 'ti ti-adjustments',
                'group' => self::GROUP_FOUNDATION,
                'is_core' => true,
                'capabilities' => [
                    ['key' => 'settings.manage', 'name' => 'Change field settings and naming', 'scope' => self::SCOPE_ORGANIZATION],
                    ['key' => 'settings.audit', 'name' => 'Read the organization-wide activity log', 'scope' => self::SCOPE_ORGANIZATION],
                ],
            ],

            /*
            |------------------------------------------------------------
            | Sold separately — bound per organization.
            |------------------------------------------------------------
            |
            | A module belongs here once its screens exist, or once it is
            | the next phase of a plan that is already settled. Modules
            | invented ahead of any of that were removed: a catalogue row
            | an administrator can switch on that then does nothing is a
            | promise the software does not keep, and it puts capabilities
            | on the role screen that no route will ever ask about.
            */
            [
                'key' => 'appointments',
                'name' => 'Appointments',
                'description' => 'Doctors, their sittings, and the OPD queue.',
                'icon' => 'ti ti-calendar-event',
                'group' => self::GROUP_CLINICAL,
                'is_core' => false,
                /*
                 * Split along the lines a busy desk actually draws. Taking a
                 * booking and calling the next patient in are what everybody
                 * at the desk does all day; cancelling one and marking a
                 * no-show change what the day is recorded as having been, and
                 * plenty of clinics want those with somebody senior.
                 *
                 * Who the doctors are is a separate question again from when
                 * they sit — the first changes rarely, the second every time
                 * somebody takes leave.
                 */
                'capabilities' => [
                    ['key' => 'appointments.view', 'name' => 'View doctors, availability and the queue', 'scope' => self::SCOPE_BRANCH],
                    ['key' => 'appointments.book', 'name' => 'Book appointments and take walk-ins', 'scope' => self::SCOPE_BRANCH],
                    ['key' => 'appointments.queue', 'name' => 'Check in, call through and complete', 'scope' => self::SCOPE_BRANCH],
                    ['key' => 'appointments.cancel', 'name' => 'Cancel and mark no-shows', 'scope' => self::SCOPE_BRANCH],
                    ['key' => 'appointments.doctors', 'name' => 'Add and edit doctors', 'scope' => self::SCOPE_BRANCH],
                    ['key' => 'appointments.schedule', 'name' => 'Set timings, leave and extra sessions', 'scope' => self::SCOPE_BRANCH],
                ],
            ],
            [
                'key' => 'prescriptions',
                'name' => 'Prescriptions',
                'description' => 'Consultations, prescriptions and the patient timeline.',
                'icon' => 'ti ti-stethoscope',
                'group' => self::GROUP_CLINICAL,
                'is_core' => false,
                'capabilities' => [
                    ['key' => 'prescriptions.view', 'name' => 'Read prescriptions', 'scope' => self::SCOPE_BRANCH],
                    ['key' => 'prescriptions.write', 'name' => 'Write prescriptions', 'scope' => self::SCOPE_BRANCH],
                ],
            ],
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_column(self::all(), 'key');
    }

    public static function supports(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }

    /** @return array<string, mixed>|null */
    public static function find(string $key): ?array
    {
        foreach (self::all() as $module) {
            if ($module['key'] === $key) {
                return $module;
            }
        }

        return null;
    }

    /**
     * The modules nobody can be without.
     *
     * @return list<string>
     */
    public static function coreKeys(): array
    {
        return array_column(
            array_filter(self::all(), fn (array $module) => $module['is_core']),
            'key'
        );
    }

    /**
     * Which module grants a capability.
     *
     * The join that makes route-level permissions possible: a route says
     * which capability it needs, and this says which entitlement has to be
     * in place before anybody's role can even be asked. Level two adds the
     * role check beside it; nothing here changes.
     */
    public static function moduleForCapability(string $capability): ?string
    {
        foreach (self::all() as $module) {
            foreach ($module['capabilities'] as $entry) {
                if ($entry['key'] === $capability) {
                    return $module['key'];
                }
            }
        }

        return null;
    }

    /**
     * Every capability these modules grant, in registry order.
     *
     * @param  list<string>  $moduleKeys
     * @return list<string>
     */
    public static function capabilitiesFor(array $moduleKeys): array
    {
        $capabilities = [];

        foreach (self::all() as $module) {
            if (! in_array($module['key'], $moduleKeys, true)) {
                continue;
            }

            foreach ($module['capabilities'] as $capability) {
                $capabilities[] = $capability['key'];
            }
        }

        return $capabilities;
    }

    /** Every capability the software defines, in registry order. */
    /** @return list<string> */
    public static function allCapabilities(): array
    {
        return self::capabilitiesFor(self::keys());
    }

    /**
     * Whether a capability exists at all.
     *
     * Validation asks this before storing it on a role, so a typo — or a
     * capability left behind by a module that was retired — cannot be saved
     * and then silently never match anything.
     */
    public static function supportsCapability(string $capability): bool
    {
        return in_array($capability, self::allCapabilities(), true);
    }

    /**
     * Where a capability can mean anything — see the SCOPE_* constants.
     *
     * Null for a key the registry does not know, so a caller can tell "no
     * scope recorded" from "organization" rather than guessing.
     */
    public static function capabilityScope(string $capability): ?string
    {
        foreach (self::all() as $module) {
            foreach ($module['capabilities'] as $entry) {
                if ($entry['key'] === $capability) {
                    return $entry['scope'];
                }
            }
        }

        return null;
    }

    /**
     * The catalogue shaped for the role screen: modules, each with its
     * capabilities, limited to what these module keys allow.
     *
     * `$scope` narrows it further to the capabilities that mean anything at
     * that scope. A branch role is never offered `settings.manage`, because
     * there is no version of it that applies at one branch and not another —
     * offering it would be a choice the software could not honour.
     *
     * Modules left with no capabilities after the filter are dropped: an empty
     * heading is worse than an absent one.
     *
     * @param  list<string>  $moduleKeys
     * @return list<array{key: string, name: string, icon: string, capabilities: list<array<string, string>>}>
     */
    public static function grantable(array $moduleKeys, ?string $scope = null): array
    {
        $grantable = [];

        foreach (self::all() as $module) {
            if (! in_array($module['key'], $moduleKeys, true)) {
                continue;
            }

            $capabilities = $scope === null
                ? $module['capabilities']
                : array_values(array_filter(
                    $module['capabilities'],
                    fn (array $entry) => $entry['scope'] === $scope,
                ));

            if ($capabilities === []) {
                continue;
            }

            $grantable[] = [
                'key' => $module['key'],
                'name' => $module['name'],
                'icon' => $module['icon'],
                'capabilities' => $capabilities,
            ];
        }

        return $grantable;
    }
}
