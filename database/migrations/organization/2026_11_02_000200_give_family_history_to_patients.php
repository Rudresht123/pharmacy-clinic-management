<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lets the patients who already have logins read their family's history.
 *
 * `portal.family.history` is new, and the code that writes the Patient role
 * now includes it — but that role is only written when it is first created.
 *
 * WHO GETS IT: a Patient role that can still look after a family. An owner who
 * took that off has said patients do not, and this does not hand it back.
 *
 * The DB facade rather than the models: a migration has to keep replaying
 * against the schema as it was on the day it was written.
 */
return new class extends Migration
{
    private const NEEDS = 'portal.family';

    private const GRANTS = 'portal.family.history';

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
