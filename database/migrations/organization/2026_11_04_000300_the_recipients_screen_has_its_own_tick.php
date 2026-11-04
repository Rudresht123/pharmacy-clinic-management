<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Who gets each message moved to one central screen, with a tick of its own.
 *
 * Whoever could change it before, on the rule itself, held `communication.manage`,
 * so they get the new tick and nobody loses the ability on the day this ships. What
 * a branch took off a role, or a person was denied, stays taken off.
 *
 * The DB facade rather than the models: a migration has to keep replaying against
 * the schema as it was on the day it was written.
 */
return new class extends Migration
{
    private const HELD = 'communication.manage';

    private const GRANT = 'communication.recipients';

    public function up(): void
    {
        $now = now();

        foreach (DB::table('role_capabilities')->where('capability', self::HELD)->pluck('role_id') as $roleId) {
            DB::table('role_capabilities')->updateOrInsert(
                ['role_id' => $roleId, 'capability' => self::GRANT],
                ['created_at' => $now, 'updated_at' => $now],
            );
        }

        $this->carryOver('branch_role_capabilities', ['location_id', 'role_id'], $now);
        $this->carryOver('user_permission_overrides', ['user_id', 'location_id', 'effect'], $now);
    }

    public function down(): void
    {
        foreach (['role_capabilities', 'branch_role_capabilities', 'user_permission_overrides'] as $table) {
            DB::table($table)->where('capability', self::GRANT)->delete();
        }
    }

    /**
     * @param  list<string>  $keys  the columns that identify one removal, besides the capability
     */
    private function carryOver(string $table, array $keys, mixed $now): void
    {
        foreach (DB::table($table)->where('capability', self::HELD)->get() as $row) {
            $match = ['capability' => self::GRANT];

            foreach ($keys as $key) {
                $match[$key] = $row->{$key};
            }

            DB::table($table)->updateOrInsert(
                $match,
                ['created_by' => $row->created_by ?? null, 'created_at' => $now, 'updated_at' => $now],
            );
        }
    }
};
