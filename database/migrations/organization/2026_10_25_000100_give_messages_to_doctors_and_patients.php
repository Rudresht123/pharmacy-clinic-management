<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lets the doctors and patients who already have logins use messaging.
 *
 * `appointments.messages` and `portal.messages` are new, and the code that
 * writes the Doctor and Patient roles now includes them — but the Patient role
 * is only written when it is first created, and the Doctor role only when a
 * doctor's login is next saved. Without this, nobody already signed up would
 * see a Messages screen until something unrelated touched their role.
 *
 * WHO GETS IT: the shared role (by slug) that still does the job the new key
 * belongs to. A Doctor role that can still start a consultation messages its
 * patients; a Patient role that can still see its own appointments messages
 * its doctors. An owner who took those away has said what the role is for,
 * and this does not hand anything back.
 *
 * The DB facade rather than the models: a migration has to keep replaying
 * against the schema as it was on the day it was written.
 */
return new class extends Migration
{
    /** @var array<string, array{0: string, 1: string}> slug => [needs, grants] */
    private const GRANTS = [
        'doctor' => ['appointments.consult_start', 'appointments.messages'],
        'patient' => ['portal.appointments.view', 'portal.messages'],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::GRANTS as $slug => [$needs, $grants]) {
            $roleIds = DB::table('roles')
                ->where('slug', $slug)
                ->whereIn('id', DB::table('role_capabilities')->where('capability', $needs)->select('role_id'))
                ->pluck('id');

            foreach ($roleIds as $roleId) {
                DB::table('role_capabilities')->updateOrInsert(
                    ['role_id' => $roleId, 'capability' => $grants],
                    ['updated_at' => $now, 'created_at' => $now],
                );
            }
        }
    }

    public function down(): void
    {
        foreach (self::GRANTS as $slug => [, $grants]) {
            DB::table('role_capabilities')
                ->whereIn('role_id', DB::table('roles')->where('slug', $slug)->select('id'))
                ->where('capability', $grants)
                ->delete();
        }
    }
};
