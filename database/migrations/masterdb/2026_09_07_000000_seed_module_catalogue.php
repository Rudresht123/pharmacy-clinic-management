<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fills the module catalogue, and removes the three modules that were only
 * ever a placeholder.
 *
 * Until now the catalogue was populated by `php artisan modules:sync` alone,
 * which meant a fresh install had a `modules` table with nothing in it until
 * somebody remembered to run a command. Nothing that the rest of the software
 * treats as a foreign key should depend on being remembered, so the rows land
 * here. `modules:sync` stays: it reconciles the catalogue with the registry on
 * every deploy, which is a different job from getting a new database usable.
 *
 * `inventory`, `sales` and `wholesale` are deleted rather than deactivated.
 * SyncModulesCommand deliberately deactivates instead of deleting, because a
 * retired module usually has entitlements — and billing history — pointing at
 * it. These three never do: they were declared before any screen existed, were
 * never sold, and their only bindings are on development organizations. A
 * catalogue row a super admin can switch on that then does nothing is worse
 * than no row, and their capabilities would sit on the role screen with no
 * route that ever asks about them.
 *
 * The DB facade rather than the Module model is deliberate and is not an
 * exception to the project's Models-not-facade rule: that rule is about
 * application code. A migration has to keep working against the schema as it
 * was on the day it was written, and a model changes underneath it — rename a
 * column in a year and every fresh install fails replaying this file.
 */
return new class extends Migration
{
    /**
     * Frozen on purpose — a literal copy of ModuleRegistry as it stood today,
     * not a read of it. See the class docblock.
     */
    private const CATALOGUE = [
        ['key' => 'branches', 'name' => 'Branches', 'description' => 'The stores, warehouses and sites an organization operates.', 'group' => 'foundation', 'icon' => 'ti ti-building-store', 'is_core' => true],
        ['key' => 'people', 'name' => 'People', 'description' => 'The staff who sign in, and the branch each one works at.', 'group' => 'foundation', 'icon' => 'ti ti-users-group', 'is_core' => true],
        ['key' => 'customers', 'name' => 'Customers & Patients', 'description' => 'One record per person served, shared by every branch.', 'group' => 'foundation', 'icon' => 'ti ti-users', 'is_core' => true],
        ['key' => 'settings', 'name' => 'Configuration', 'description' => 'Field settings and what the organization calls each record.', 'group' => 'foundation', 'icon' => 'ti ti-adjustments', 'is_core' => true],
        ['key' => 'appointments', 'name' => 'Appointments', 'description' => 'Doctors, their sittings, and the OPD queue.', 'group' => 'clinical', 'icon' => 'ti ti-calendar-event', 'is_core' => false],
        ['key' => 'prescriptions', 'name' => 'Prescriptions', 'description' => 'Consultations, prescriptions and the patient timeline.', 'group' => 'clinical', 'icon' => 'ti ti-stethoscope', 'is_core' => false],
    ];

    private const RETIRED = ['inventory', 'sales', 'wholesale'];

    public function up(): void
    {
        $now = now();

        foreach (self::CATALOGUE as $order => $module) {
            DB::table('modules')->updateOrInsert(
                ['key' => $module['key']],
                [
                    'name' => $module['name'],
                    'description' => $module['description'],
                    'group' => $module['group'],
                    'icon' => $module['icon'],
                    'is_core' => $module['is_core'],
                    'sort_order' => $order,
                    'is_active' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        $retiredIds = DB::table('modules')->whereIn('key', self::RETIRED)->pluck('id');

        if ($retiredIds->isEmpty()) {
            return;
        }

        /*
         * Deleted explicitly rather than left to the foreign key's cascade.
         * Entitlements are a billing fact; one going away should be readable
         * in the file that caused it, not inferred from a constraint.
         */
        DB::table('organization_modules')->whereIn('module_id', $retiredIds)->delete();
        DB::table('modules')->whereIn('id', $retiredIds)->delete();
    }

    public function down(): void
    {
        // The catalogue rows are restored by `modules:sync`, and the three
        // retired modules no longer exist in the registry to restore. Undoing
        // this migration cannot invent the entitlements it deleted, so it does
        // not pretend to.
    }
};
