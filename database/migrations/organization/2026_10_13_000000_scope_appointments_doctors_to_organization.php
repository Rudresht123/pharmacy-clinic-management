<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `appointments.doctors` moved from branch scope to organization scope.
 *
 * A doctor is the organization's, not one branch's — their profile is
 * written once and they may cover more than one branch — so registering one
 * is now head office's capability, and a branch role cannot hold it
 * (SaveRoleRequest refuses it going forward). This strips it from the role
 * capabilities written before that rule existed; a branch role's reach over
 * its own doctors' postings and timings, `appointments.schedule`, is
 * untouched.
 *
 * The DB facade rather than the Role model, same reason as the migration
 * this follows on from (2026_09_09_000000_split_manage_capabilities): a
 * migration has to keep replaying correctly against the schema as it was the
 * day it was written.
 */
return new class extends Migration
{
    public function up(): void
    {
        $branchRoleIds = DB::table('roles')->where('scope', 'branch')->pluck('id');

        DB::table('role_capabilities')
            ->where('capability', 'appointments.doctors')
            ->whereIn('role_id', $branchRoleIds)
            ->delete();
    }

    /**
     * Not reversible in the general sense — which branch roles held it is
     * gone the moment `up()` runs. Re-grants it to every branch role that
     * still holds `appointments.schedule`, which is the closest honest
     * approximation of who had it before.
     */
    public function down(): void
    {
        $now = now();

        $roleIds = DB::table('role_capabilities')
            ->where('capability', 'appointments.schedule')
            ->pluck('role_id')
            ->unique();

        foreach ($roleIds as $roleId) {
            DB::table('role_capabilities')->updateOrInsert(
                ['role_id' => $roleId, 'capability' => 'appointments.doctors'],
                ['updated_at' => $now, 'created_at' => $now],
            );
        }
    }
};
