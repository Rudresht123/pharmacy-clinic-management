<?php

namespace App\Services\Tenant;

use App\Models\Tenant\Doctor;
use App\Models\Tenant\Role;
use App\Models\Tenant\User;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Gives a doctor a way to sign in — or takes it away.
 *
 * `Doctor::user()` and the login's `doctor_id` have existed since Phase 1, and
 * nothing ever wrote the link between them: the morphOne was there, the unique
 * index was there, the queue screen was ready to open on a doctor's own list,
 * and no doctor could actually log in because no code path set
 * `userable_type`. This is that path.
 *
 * A doctor may have NO login — a visiting consultant who never touches the
 * system is the reason `doctors` is its own table rather than a role on
 * `users`. A doctor may never have TWO: Phase 4 asks "who wrote this
 * prescription", and two accounts give two answers.
 */
class DoctorAccountProvisioner
{
    /**
     * What a doctor's own login may do.
     *
     * Their own list, their own patients, and writing what happened. Not the
     * desk's work — booking, cancelling and calling people through belong to
     * whoever is running the queue, and a doctor holding them would be able to
     * rearrange a morning they cannot see.
     */
    private const WANTED = [
        'appointments.view',
        'appointments.queue',
        'customers.view',
        'prescriptions.view',
        'prescriptions.write',
    ];

    /** The slug of the role every doctor login shares. */
    public const ROLE = 'doctor';

    /**
     * @param  array{email: string, password?: string|null}  $account
     * @param  list<string>  $modules  what the organization runs
     */
    public function open(Doctor $doctor, array $account, array $modules): User
    {
        $existing = $doctor->user()->first();

        if ($existing) {
            /*
             * Editing the login rather than opening a second one. The partial
             * unique index would refuse the insert anyway; catching it here
             * makes the refusal a sentence instead of a 23505.
             */
            $existing->fill([
                'name' => $doctor->name,
                'email' => $account['email'],
                'is_active' => true,
            ]);

            if (! empty($account['password'])) {
                $existing->password = Hash::make($account['password']);
            }

            $existing->save();

            return $existing;
        }

        if (empty($account['password'])) {
            throw new RuntimeException('A new login needs a password.');
        }

        return User::create([
            'name' => $doctor->name,
            'email' => $account['email'],
            'password' => Hash::make($account['password']),
            'is_active' => true,
            'role' => User::STAFF,

            /*
             * The role is organization-wide rather than a branch membership: a
             * doctor sits wherever their timings put them, and tying their
             * account to one branch would hide the other branches' lists from
             * the one person who has to work them.
             */
            'role_id' => $this->role($modules)->id,

            'userable_type' => Doctor::class,
            'userable_id' => $doctor->id,
        ]);
    }

    /** Take the login away, leaving the doctor and their history behind. */
    public function close(Doctor $doctor): void
    {
        /*
         * Soft-deleted, not detached. Unsetting `userable_id` would leave an
         * account that can still sign in and is no longer anybody — worse than
         * either keeping it or removing it.
         */
        $doctor->user()->first()?->delete();
    }

    /**
     * The shared "Doctor" role, created once and reused.
     *
     * One role rather than one per doctor: they all do the same job, and a
     * role per person makes changing what doctors may do a loop instead of an
     * edit.
     */
    private function role(array $modules): Role
    {
        $role = Role::firstOrCreate(
            ['slug' => self::ROLE],
            [
                'name' => 'Doctor',
                'scope' => Role::SCOPE_ORGANIZATION,
                'icon' => 'ti ti-stethoscope',
                'description' => 'Sees their own list and writes up what happened.',
            ],
        );

        /*
         * Only what the organization actually runs. A capability whose module
         * is switched off would be a permission that means nothing, and
         * SaveRoleRequest would refuse it from the screen anyway.
         */
        $available = \App\Support\Modules\ModuleRegistry::capabilitiesFor($modules);

        $role->load('capabilities')->syncCapabilities(
            array_values(array_intersect(self::WANTED, $available))
        );

        return $role;
    }
}
