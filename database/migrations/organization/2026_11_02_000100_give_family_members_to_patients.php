<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lets the patients who already have logins add their family.
 *
 * `portal.family` is new, and the code that writes the Patient role now
 * includes it — but that role is only written when it is first created.
 * Without this, nobody already signed up would see Family members until
 * something unrelated touched their role.
 *
 * WHO GETS IT: a Patient role that can still book. An owner who took booking
 * off the role has said patients do not book from the app, and booking for a
 * family is no different; this does not hand anything back.
 *
 * The DB facade rather than the models: a migration has to keep replaying
 * against the schema as it was on the day it was written.
 */
return new class extends Migration
{
    private const NEEDS = 'portal.appointments.book';

    private const GRANTS = 'portal.family';

    public function up(): void
    {
        $now = now();

        $roleIds = DB::table('roles')
            ->where('slug', 'patient')
            ->whereIn('id', DB::table('role_capabilities')->where('capability', self::NEEDS)->select('role_id'))
            ->pluck('id');

        foreach ($roleIds as $roleId) {
            DB::table('role_capabilities')->updateOrInsert(
                ['role_id' => $roleId, 'capability' => self::GRANTS],
                ['updated_at' => $now, 'created_at' => $now],
            );
        }
    }

    public function down(): void
    {
        DB::table('role_capabilities')
            ->whereIn('role_id', DB::table('roles')->where('slug', 'patient')->select('id'))
            ->where('capability', self::GRANTS)
            ->delete();
    }
};
