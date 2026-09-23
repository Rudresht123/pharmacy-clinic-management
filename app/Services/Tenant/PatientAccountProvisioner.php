<?php

namespace App\Services\Tenant;

use App\Models\Tenant\Customer;
use App\Models\Tenant\Role;
use App\Models\Tenant\User;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Gives a patient a login of their own — the patient-side twin of
 * DoctorAccountProvisioner, built the same way.
 *
 * A patient login is a `users` row whose `userable` is their customer record,
 * of the PATIENT kind, holding the shared "Patient" role. What that role may
 * do is ordinary role data the owner can edit on the Roles screen; nothing in
 * the app or the API asks whether somebody "is a patient" before deciding what
 * they may do.
 *
 * One login per patient, ever: the partial unique index on
 * (userable_type, userable_id) that holds for doctors holds here too.
 */
class PatientAccountProvisioner
{
    /** The slug of the role every patient login shares. */
    public const ROLE = 'patient';

    /**
     * What a patient's login may do when the role is first made.
     *
     * Only own-data capabilities. Filtered against what the organization runs,
     * so a clinic without a module is not handed permissions that mean nothing.
     */
    private const DEFAULTS = [
        'portal.appointments.view',
        'portal.appointments.book',
    ];

    /**
     * The patient's login, opened on first sign-in and reused after.
     *
     * @param  list<string>  $modules  what the organization runs
     */
    public function open(Customer $customer, array $modules): User
    {
        $existing = $customer->user()->first();

        if ($existing) {
            return $existing;
        }

        return User::create([
            'name' => $customer->name,

            // Signed in with a code, never a password. The column is required,
            // so it holds a random hash nobody knows — which the staff login
            // can therefore never match.
            'email' => null,
            'password' => Hash::make(Str::random(40)),

            'is_active' => true,
            'role' => User::PATIENT,
            'role_id' => $this->role($modules)->id,

            'userable_type' => Customer::class,
            'userable_id' => $customer->id,
        ]);
    }

    /**
     * The shared "Patient" role, created once.
     *
     * Unlike the doctor role, its capabilities are written only when it is
     * CREATED. After that the role is the owner's to shape — a clinic that
     * does not want patients booking from the app takes that capability off
     * the role, and the next sign-in must not quietly put it back.
     */
    private function role(array $modules): Role
    {
        $role = Role::firstOrCreate(
            ['slug' => self::ROLE],
            [
                'name' => 'Patient',
                'scope' => Role::SCOPE_ORGANIZATION,
                'icon' => 'ti ti-users',
                'description' => 'Patients using the app: their own appointments and bookings.',
            ],
        );

        if ($role->wasRecentlyCreated) {
            $role->syncCapabilities(array_values(array_intersect(
                self::DEFAULTS,
                ModuleRegistry::capabilitiesFor($modules),
            )));
        }

        return $role;
    }
}
