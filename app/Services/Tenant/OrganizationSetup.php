<?php

namespace App\Services\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\Department;
use App\Models\Tenant\EntityFieldSetting;
use App\Models\Tenant\EntityLabel;
use App\Models\Tenant\Location;
use App\Models\Tenant\Role;
use App\Models\Tenant\SetupStep;
use App\Models\Tenant\User;
use App\Services\Fields\FieldSchema;
use App\Services\Permissions\Permission;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Where an organization stands in setting itself up.
 *
 * Read from the organization's own data wherever the data can answer — its
 * details are filled in, it has a branch, it has staff — so an organization
 * that was set up before this screen existed shows as set up, and a section
 * can never claim to be done while its data says otherwise.
 *
 * What data cannot answer is a sign-off: that the department list is right,
 * that somebody looked at the roles, that setup is finished. Those are rows
 * in `setup_steps`, written only after the section's own data has saved.
 *
 * Nothing here is not already the organization's: the tenant database is the
 * boundary, so one organization's progress cannot be mixed with another's.
 */
class OrganizationSetup
{
    public const ORGANIZATION = 'organization';

    public const BRANCHES = 'branches';

    public const DEPARTMENTS = 'departments';

    public const USERS = 'users';

    public const ROLES = 'roles';

    public const SETTINGS = 'settings';

    public const REVIEW = 'review';

    /** In the order the menu shows them. */
    public const STEPS = [
        self::ORGANIZATION, self::BRANCHES, self::DEPARTMENTS, self::USERS,
        self::ROLES, self::SETTINGS, self::REVIEW,
    ];

    /** Sections the admin signs off, rather than ones read from data. */
    public const CONFIRMABLE = [self::DEPARTMENTS, self::ROLES, self::SETTINGS];

    /** What the organisation's record needs before setup can finish. */
    private const REQUIRED_DETAILS = [
        'organization_name' => 'the organisation name',
        'contact_person_name' => 'a contact person',
        'phone_number' => 'a phone number',
        'address' => 'an address',
    ];

    /** Where it is, on the platform's `organization_profiles` row. */
    private const REQUIRED_ADDRESS = [
        'city' => 'the city',
        'state_province' => 'the state',
        'postal_code' => 'the postal code',
    ];

    /** Roles the software writes itself; any other one is the organization's own. */
    private const SOFTWARE_ROLES = [Role::SEEDED_STAFF, DoctorAccountProvisioner::ROLE];

    public function __construct(
        private readonly FieldSchema $schema,
        private readonly Permission $permission,
    ) {}

    /**
     * Every section's state, and the whole.
     *
     * @return array<string, mixed>
     */
    public function status(Organization $organization): array
    {
        $signed = SetupStep::query()->get()->keyBy('step');

        $steps = [
            $this->organizationStep($organization),
            $this->branchesStep(),
            $this->departmentsStep($organization, $signed),
            $this->usersStep(),
            $this->rolesStep($signed),
            $this->settingsStep($signed),
        ];

        $blocking = array_values(array_filter($steps, fn (array $step) => $step['mandatory'] && ! $step['completed']));
        $finished = $signed->get(self::REVIEW);

        $steps[] = $this->step(
            self::REVIEW,
            mandatory: true,
            completed: $finished !== null,
            missing: $finished !== null ? [] : ($blocking === []
                ? ['Complete the organisation setup.']
                : array_map(fn (array $step) => "Finish {$this->title($step['key'])} first.", $blocking)),
            completedAt: $finished?->completed_at,
        );

        $completed = count(array_filter($steps, fn (array $step) => $step['completed']));

        // Where the screen opens: the first required section still to do.
        $current = ($blocking[0]['key'] ?? null)
            ?? collect($steps)->first(fn (array $step) => ! $step['completed'])['key']
            ?? self::REVIEW;

        return [
            'steps' => $steps,
            'total' => count($steps),
            'completed_count' => $completed,
            'percent' => (int) round($completed / count($steps) * 100),
            'current' => $current,
            'can_complete' => $blocking === [],
            'completed_at' => $finished?->completed_at?->toIso8601String(),
        ];
    }

    /**
     * Whether the owner has finished setup — the one question the sign-in
     * session asks, so the workspace can stay closed until it is answered.
     */
    public function isComplete(): bool
    {
        return SetupStep::query()->where('step', self::REVIEW)->exists();
    }

    /**
     * Sign off a section whose data has already been saved.
     *
     * Refused while the section has nothing to sign off, so a click cannot
     * claim a department list that has no departments.
     */
    public function confirm(string $step): void
    {
        if ($step === self::DEPARTMENTS && $this->departmentCount() === 0) {
            throw ValidationException::withMessages([
                'step' => 'Add at least one department before confirming the list.',
            ]);
        }

        if ($step === self::ROLES && ! Role::query()->exists()) {
            throw ValidationException::withMessages([
                'step' => 'There are no roles to review yet.',
            ]);
        }

        $this->sign($step);
    }

    /**
     * Finish the setup, once every required section is done.
     *
     * The rule is checked here rather than trusted from the screen. Finishing
     * twice keeps the first date.
     */
    public function complete(Organization $organization): void
    {
        $status = $this->status($organization);

        if ($status['completed_at'] !== null) {
            return;
        }

        $blocking = array_filter(
            $status['steps'],
            fn (array $step) => $step['key'] !== self::REVIEW && $step['mandatory'] && ! $step['completed'],
        );

        if ($blocking !== []) {
            $messages = [];

            foreach ($blocking as $step) {
                $messages["steps.{$step['key']}"] = $step['missing'];
            }

            throw ValidationException::withMessages($messages);
        }

        $this->sign(self::REVIEW);
    }

    /*
    |--------------------------------------------------------------------------
    | The sections
    |--------------------------------------------------------------------------
    */

    private function organizationStep(Organization $organization): array
    {
        $missing = [];

        foreach (self::REQUIRED_DETAILS as $field => $words) {
            if (blank($organization->{$field})) {
                $missing[] = "Add {$words}.";
            }
        }

        // Where it is: kept on the platform's `organization_profiles` row.
        foreach (self::REQUIRED_ADDRESS as $field => $words) {
            if (blank($organization->profile?->{$field})) {
                $missing[] = "Add {$words}.";
            }
        }

        return $this->step(self::ORGANIZATION, mandatory: true, completed: $missing === [], missing: $missing);
    }

    private function branchesStep(): array
    {
        $active = Location::query()->active()->count();

        return $this->step(
            self::BRANCHES,
            mandatory: true,
            completed: $active > 0,
            missing: $active > 0 ? [] : ['Add at least one active clinic or branch.'],
            counts: [
                'branches' => Location::query()->count(),
                'active' => $active,
                'clinics' => Location::query()->active()->where('type', Location::CLINIC)->count(),
            ],
        );
    }

    /**
     * Departments are the doctor form's own list. Required where the
     * organization runs OPD, which is where doctors are chosen by department.
     *
     * @param  Collection<string, SetupStep>  $signed
     */
    private function departmentsStep(Organization $organization, Collection $signed): array
    {
        $count = $this->departmentCount();

        // Saved once through the settings screen is as good as signed off here.
        $saved = $signed->has(self::DEPARTMENTS) || EntityFieldSetting::query()
            ->where('entity', EntityFieldSetting::ENTITY_DOCTOR)
            ->where('field_key', 'specialisation')
            ->exists();

        $missing = match (true) {
            $count === 0 => ['Add at least one department.'],
            ! $saved => ['Check the department list and save it.'],
            default => [],
        };

        return $this->step(
            self::DEPARTMENTS,
            mandatory: $this->permission->hasModule($organization, 'appointments'),
            completed: $missing === [],
            missing: $missing,
            counts: ['departments' => $count],
            completedAt: $signed->get(self::DEPARTMENTS)?->completed_at,
        );
    }

    /** Optional: an owner working alone is a real organization. */
    private function usersStep(): array
    {
        $staff = User::query()->where('role', User::STAFF)->where('is_active', true)->count();

        return $this->step(
            self::USERS,
            mandatory: false,
            completed: $staff > 0,
            missing: $staff > 0 ? [] : ['Add the people who will use the software, or carry on alone.'],
            counts: ['staff' => $staff, 'people' => User::query()->count()],
        );
    }

    /** @param  Collection<string, SetupStep>  $signed */
    private function rolesStep(Collection $signed): array
    {
        $roles = Role::query()->get(['id', 'slug']);

        $own = $roles->reject(fn (Role $role) => in_array($role->slug, self::SOFTWARE_ROLES, true)
            || str_starts_with((string) $role->slug, 'branch-admin-'))->count();

        $done = $signed->has(self::ROLES) || $own > 0;

        return $this->step(
            self::ROLES,
            mandatory: false,
            completed: $done,
            missing: $done ? [] : ['Look over the roles and what each one may do.'],
            counts: ['roles' => $roles->count()],
            completedAt: $signed->get(self::ROLES)?->completed_at,
        );
    }

    /** @param  Collection<string, SetupStep>  $signed */
    private function settingsStep(Collection $signed): array
    {
        $done = $signed->has(self::SETTINGS)
            || EntityLabel::query()->where('entity', EntityFieldSetting::ENTITY_CUSTOMER)->exists();

        return $this->step(
            self::SETTINGS,
            mandatory: false,
            completed: $done,
            missing: $done ? [] : ['Check what you call patients and which modules each branch runs.'],
            completedAt: $signed->get(self::SETTINGS)?->completed_at,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /** How many active departments and sub-departments the organisation has. */
    private function departmentCount(): int
    {
        return Department::query()->where('is_active', true)->count();
    }

    private function sign(string $step): void
    {
        SetupStep::query()->updateOrCreate(
            ['step' => $step],
            ['completed_at' => now(), 'completed_by' => Auth::guard('web')->id()],
        );
    }

    /**
     * @param  list<string>  $missing
     * @param  array<string, int>  $counts
     */
    private function step(
        string $key,
        bool $mandatory,
        bool $completed,
        array $missing = [],
        array $counts = [],
        mixed $completedAt = null,
    ): array {
        return [
            'key' => $key,
            'mandatory' => $mandatory,
            'completed' => $completed,
            // Completed, or a required section still to do, or an optional one not done.
            'status' => $completed ? 'completed' : ($mandatory ? 'incomplete' : 'pending'),
            'missing' => $missing,
            'counts' => (object) $counts,
            'completed_at' => $completedAt?->toIso8601String(),
        ];
    }

    private function title(string $key): string
    {
        return [
            self::ORGANIZATION => 'Organisation Information',
            self::BRANCHES => 'Clinic / Branch Setup',
            self::DEPARTMENTS => 'Departments',
            self::USERS => 'Users & Staff',
            self::ROLES => 'Roles & Permissions',
            self::SETTINGS => 'Clinic Settings',
        ][$key] ?? $key;
    }
}
