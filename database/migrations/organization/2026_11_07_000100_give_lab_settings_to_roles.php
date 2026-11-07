<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hands Settings › Laboratory to the roles already doing that kind of work.
 *
 * A template only writes a role when it is first seeded, so nobody already set
 * up would see the new screen until something unrelated rewrote their role.
 * Decided by what a role already HOLDS rather than by its slug: each branch has
 * its own copy of every role, renamed as it likes.
 *
 *   laboratory.settings_manage  whoever keeps the lab's price list today
 *                               (`billing.manage_services`, head office)
 *   laboratory.settings_view    the same, plus whoever signs lab results off
 *                               and whoever administers a branch that runs a lab
 *
 * Nothing changes for an organisation without the laboratory module: Permission
 * asks about the module before it reads a role.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->grant('laboratory.settings_manage', [['billing.manage_services']]);

        $this->grant('laboratory.settings_view', [
            ['billing.manage_services'],
            ['laboratory.complete'],
            ['laboratory.view', 'people.roles'],
        ]);
    }

    public function down(): void
    {
        DB::table('role_capabilities')
            ->whereIn('capability', ['laboratory.settings_view', 'laboratory.settings_manage'])
            ->delete();
    }

    /**
     * @param  list<list<string>>  $anyOf  a role qualifies by holding every key of any one set
     */
    private function grant(string $capability, array $anyOf): void
    {
        $now = now();
        $roleIds = collect();

        foreach ($anyOf as $holding) {
            $ids = null;

            foreach ($holding as $held) {
                $holders = DB::table('role_capabilities')->where('capability', $held)->pluck('role_id');
                $ids = $ids === null ? $holders : $ids->intersect($holders);
            }

            $roleIds = $roleIds->merge($ids ?? []);
        }

        foreach ($roleIds->unique() as $roleId) {
            DB::table('role_capabilities')->updateOrInsert(
                ['role_id' => $roleId, 'capability' => $capability],
                ['updated_at' => $now, 'created_at' => $now],
            );
        }
    }
};
