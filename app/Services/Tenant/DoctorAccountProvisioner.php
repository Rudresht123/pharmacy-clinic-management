<?php

namespace App\Services\Tenant;

use App\Models\Tenant\Doctor;
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
 *
 * What the login may do is each BRANCH's to say: it holds that branch's Doctor
 * role at every branch the doctor is posted to (DoctorPostings::seat), and no
 * role of the organization's.
 */
class DoctorAccountProvisioner
{
    /** The slug of the organization-wide role doctors used to share — gone since roles became each branch's own. */
    public const ROLE = 'doctor';

    public function __construct(
        private readonly DoctorPostings $postings,
    ) {}

    /** @param  array{email: string, password?: string|null}  $account */
    public function open(Doctor $doctor, array $account): User
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
                'role_id' => null,
            ]);

            if (! empty($account['password'])) {
                $existing->password = Hash::make($account['password']);
            }

            $existing->save();
            $this->postings->seat($doctor);

            return $existing;
        }

        if (empty($account['password'])) {
            throw new RuntimeException('A new login needs a password.');
        }

        $login = User::create([
            'name' => $doctor->name,
            'email' => $account['email'],
            'password' => Hash::make($account['password']),
            'is_active' => true,
            'role' => User::STAFF,
            'role_id' => null,
            'userable_type' => Doctor::class,
            'userable_id' => $doctor->id,
        ]);

        $this->postings->seat($doctor);

        return $login;
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
}
