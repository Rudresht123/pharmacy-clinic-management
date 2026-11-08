<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hands the test master's ticks to the roles already doing that work.
 *
 *   laboratory.catalog_manage  whoever kept the old lab price list
 *                              (`billing.manage_services`)
 *   laboratory.catalog_view    the same, plus whoever reads the lab's settings
 *
 * `laboratory.ranges_manage` is given to nobody: reference ranges are a
 * clinical decision, and the owner ticks it onto whoever is qualified to make
 * it. Nothing changes for an organisation without the laboratory module.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->grant('laboratory.catalog_manage', [['billing.manage_services']]);

        $this->grant('laboratory.catalog_view', [
            ['billing.manage_services'],
            ['laboratory.settings_view'],
        ]);
    }

    public function down(): void
    {
        DB::table('role_capabilities')
            ->whereIn('capability', ['laboratory.catalog_view', 'laboratory.catalog_manage'])
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
