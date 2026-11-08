<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hands the Laboratory dashboard to the roles already doing lab work.
 *
 *   laboratory.dashboard  whoever works the bench (`laboratory.process`), and
 *                         whoever administers a branch that runs a lab
 *                         (`laboratory.view` + `people.roles`)
 *
 * Decided by what a role already holds, not by its slug. Nothing changes for an
 * organisation without the laboratory module.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $roleIds = collect();

        foreach ([['laboratory.process'], ['laboratory.view', 'people.roles']] as $holding) {
            $ids = null;

            foreach ($holding as $held) {
                $holders = DB::table('role_capabilities')->where('capability', $held)->pluck('role_id');
                $ids = $ids === null ? $holders : $ids->intersect($holders);
            }

            $roleIds = $roleIds->merge($ids ?? []);
        }

        foreach ($roleIds->unique() as $roleId) {
            DB::table('role_capabilities')->updateOrInsert(
                ['role_id' => $roleId, 'capability' => 'laboratory.dashboard'],
                ['updated_at' => $now, 'created_at' => $now],
            );
        }
    }

    public function down(): void
    {
        DB::table('role_capabilities')->where('capability', 'laboratory.dashboard')->delete();
    }
};
