<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One tick per screen: hands every existing role the new, smaller keys that
 * match what it could already open, so nobody's app changes on the day this
 * ships. From then on the owner takes away whatever they do not want.
 *
 *   appointments.view       → + queue_view, doctors_view (the one key used to
 *                             open all three screens)
 *   customers.view          → + record, contact (the list used to open the
 *                             record and its call buttons)
 *   appointments.consult_start → + my_day, my_queue, my_schedule, my_patients
 *                             (a doctor's own screens, shown to any doctor
 *                             login before, whatever was ticked)
 *   every staff role that is not a doctor's → + dashboard.view (everybody
 *                             but a doctor landed on the dashboard)
 *
 * The DB facade rather than the models: a migration has to keep replaying
 * against the schema as it was on the day it was written.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> held => granted alongside it */
    private const ALONGSIDE = [
        'appointments.view' => ['appointments.queue_view', 'appointments.doctors_view'],
        'customers.view' => ['customers.record', 'customers.contact'],
        'appointments.consult_start' => [
            'appointments.my_day',
            'appointments.my_queue',
            'appointments.my_schedule',
            'appointments.my_patients',
        ],
    ];

    /** @var list<string> */
    private const ADDED = [
        'appointments.queue_view',
        'appointments.doctors_view',
        'appointments.my_day',
        'appointments.my_queue',
        'appointments.my_schedule',
        'appointments.my_patients',
        'customers.record',
        'customers.contact',
        'dashboard.view',
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::ALONGSIDE as $held => $grants) {
            $roleIds = DB::table('role_capabilities')->where('capability', $held)->pluck('role_id');

            foreach ($roleIds as $roleId) {
                foreach ($grants as $grant) {
                    $this->grant($roleId, $grant, $now);
                }
            }
        }

        $doctorLike = DB::table('role_capabilities')->where('capability', 'appointments.consult_start')->select('role_id');

        $landers = DB::table('roles')
            ->whereNotIn('slug', ['doctor', 'patient'])
            ->whereNotIn('id', $doctorLike)
            ->pluck('id');

        foreach ($landers as $roleId) {
            $this->grant($roleId, 'dashboard.view', $now);
        }

        $this->carryOver('branch_role_capabilities', ['location_id', 'role_id'], $now);
        $this->carryOver('user_permission_overrides', ['user_id', 'location_id', 'effect'], $now);
    }

    public function down(): void
    {
        DB::table('role_capabilities')->whereIn('capability', self::ADDED)->delete();
        DB::table('branch_role_capabilities')->whereIn('capability', self::ADDED)->delete();
        DB::table('user_permission_overrides')->whereIn('capability', self::ADDED)->delete();
    }

    /**
     * What a branch took off a role, or a person was denied, stays taken off
     * for the smaller keys the old one is now split into — otherwise the
     * split would hand back screens somebody had deliberately removed.
     *
     * @param  list<string>  $keys  the columns that identify one removal, besides the capability
     */
    private function carryOver(string $table, array $keys, mixed $now): void
    {
        foreach (self::ALONGSIDE as $held => $grants) {
            $rows = DB::table($table)->where('capability', $held)->get();

            foreach ($rows as $row) {
                $match = [];

                foreach ($keys as $key) {
                    $match[$key] = $row->{$key};
                }

                foreach ($grants as $grant) {
                    DB::table($table)->updateOrInsert(
                        $match + ['capability' => $grant],
                        ['created_by' => $row->created_by ?? null, 'created_at' => $now, 'updated_at' => $now],
                    );
                }
            }
        }
    }

    private function grant(int $roleId, string $capability, mixed $now): void
    {
        DB::table('role_capabilities')->updateOrInsert(
            ['role_id' => $roleId, 'capability' => $capability],
            ['updated_at' => $now, 'created_at' => $now],
        );
    }
};
