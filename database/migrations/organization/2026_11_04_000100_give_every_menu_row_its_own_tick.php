<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One tick per menu row, for the rows that were still sharing one.
 *
 * Every role holding the old tick gets the new ones, so nobody's menu changes on
 * the day this ships. From then on each is a tick like any other. What a branch
 * took off a role, or a person was denied, stays taken off: the split must not hand
 * back a screen somebody deliberately removed.
 *
 * The old key stays, and keeps its first screen: `pharmacy.view` is still the
 * Pharmacy dashboard, `billing.view` the Billing overview, and so on.
 *
 * The DB facade rather than the models: a migration has to keep replaying against
 * the schema as it was on the day it was written.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> held => granted alongside it */
    private const ALONGSIDE = [
        'appointments.doctors_view' => ['appointments.availability_view'],
        'appointments.my_queue' => ['appointments.my_consultations'],
        'appointments.my_patients' => [
            'appointments.my_prescriptions',
            'appointments.my_investigations',
            'appointments.my_follow_ups',
        ],
        'settings.manage' => ['settings.departments'],
        'pharmacy.view' => [
            'pharmacy.inventory_view',
            'pharmacy.purchases_view',
            'pharmacy.bills_view',
            'pharmacy.movements_view',
            'pharmacy.suppliers_view',
            'pharmacy.stores_view',
            'pharmacy.settings_view',
        ],
        'billing.view' => [
            'billing.invoices_view',
            'billing.payments_view',
            'billing.outstanding_view',
        ],
        'communication.view' => ['communication.email', 'communication.push'],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::ALONGSIDE as $held => $grants) {
            foreach (DB::table('role_capabilities')->where('capability', $held)->pluck('role_id') as $roleId) {
                foreach ($grants as $grant) {
                    DB::table('role_capabilities')->updateOrInsert(
                        ['role_id' => $roleId, 'capability' => $grant],
                        ['created_at' => $now, 'updated_at' => $now],
                    );
                }
            }
        }

        $this->carryOver('branch_role_capabilities', ['location_id', 'role_id'], $now);
        $this->carryOver('user_permission_overrides', ['user_id', 'location_id', 'effect'], $now);
    }

    public function down(): void
    {
        $added = array_merge(...array_values(self::ALONGSIDE));

        DB::table('role_capabilities')->whereIn('capability', $added)->delete();
        DB::table('branch_role_capabilities')->whereIn('capability', $added)->delete();
        DB::table('user_permission_overrides')->whereIn('capability', $added)->delete();
    }

    /**
     * @param  list<string>  $keys  the columns that identify one removal, besides the capability
     */
    private function carryOver(string $table, array $keys, mixed $now): void
    {
        foreach (self::ALONGSIDE as $held => $grants) {
            foreach (DB::table($table)->where('capability', $held)->get() as $row) {
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
};
