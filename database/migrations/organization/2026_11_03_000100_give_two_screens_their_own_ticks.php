<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One tick per screen, for two menu rows that had none of their own.
 *
 *   customers.view → + customers.records_view  Patient Records opened on the
 *                                              same tick as the Patients list
 *   people.view    → + people.roles_view       Roles & Permissions opened on the
 *                                              same tick as the Staff list
 *
 * Every role holding the old tick gets the new one, so nobody's menu changes on
 * the day this ships. From then on it is a tick like any other. What a branch
 * took off a role, or a person was denied, stays taken off: the split must not
 * hand back a screen somebody deliberately removed.
 *
 * The DB facade rather than the models: a migration has to keep replaying
 * against the schema as it was on the day it was written.
 */
return new class extends Migration
{
    /** @var array<string, string> held => granted alongside it */
    private const ALONGSIDE = [
        'customers.view' => 'customers.records_view',
        'people.view' => 'people.roles_view',
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::ALONGSIDE as $held => $grant) {
            foreach (DB::table('role_capabilities')->where('capability', $held)->pluck('role_id') as $roleId) {
                DB::table('role_capabilities')->updateOrInsert(
                    ['role_id' => $roleId, 'capability' => $grant],
                    ['created_at' => $now, 'updated_at' => $now],
                );
            }
        }

        $this->carryOver('branch_role_capabilities', ['location_id', 'role_id'], $now);
        $this->carryOver('user_permission_overrides', ['user_id', 'location_id', 'effect'], $now);
    }

    public function down(): void
    {
        $added = array_values(self::ALONGSIDE);

        DB::table('role_capabilities')->whereIn('capability', $added)->delete();
        DB::table('branch_role_capabilities')->whereIn('capability', $added)->delete();
        DB::table('user_permission_overrides')->whereIn('capability', $added)->delete();
    }

    /**
     * @param  list<string>  $keys  the columns that identify one removal, besides the capability
     */
    private function carryOver(string $table, array $keys, mixed $now): void
    {
        foreach (self::ALONGSIDE as $held => $grant) {
            foreach (DB::table($table)->where('capability', $held)->get() as $row) {
                $match = [];

                foreach ($keys as $key) {
                    $match[$key] = $row->{$key};
                }

                DB::table($table)->updateOrInsert(
                    $match + ['capability' => $grant],
                    ['created_by' => $row->created_by ?? null, 'created_at' => $now, 'updated_at' => $now],
                );
            }
        }
    }
};
