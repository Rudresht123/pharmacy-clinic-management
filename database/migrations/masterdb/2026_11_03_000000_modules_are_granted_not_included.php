<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nothing is included with an organization any more: every module is granted.
 *
 * The seven modules `is_core` used to hand every organization now need a row
 * like any other, so without this every existing clinic would lose its patients,
 * staff, branches and billing on the next request. Each organization gets a
 * binding for each of them, whatever plan it is on, so nothing that works today
 * stops. Soft-deleted organizations too: restoring one must bring it back
 * working.
 *
 * The plans on sale gain them as well, so somebody who signs up tomorrow on a
 * plan sold as complete still gets a working clinic. Never the system plans:
 * Complimentary holds no modules of its own, and including them there would
 * bring back exactly the default this removes.
 *
 * Existing subscription snapshots are left alone. A snapshot is the agreement
 * as signed, and the bindings above already keep those organizations whole.
 *
 * The DB facade, not models, for the reason every data migration here gives:
 * this file has to replay against the schema as it is today, long after the
 * models have moved on.
 *
 * Safe to run again: every insert ignores a row that already exists.
 */
return new class extends Migration
{
    /** Frozen copy of what `is_core` marked on the day this ran. */
    private const MODULES = [
        'dashboard' => 'Dashboard',
        'branches' => 'Branches',
        'people' => 'People',
        'customers' => 'Customers & Patients',
        'exports' => 'Export Center',
        'settings' => 'Configuration',
        'billing' => 'Billing & Payments',
    ];

    private const REASON = 'Came with every organization before modules had to be granted.';

    public function up(): void
    {
        DB::transaction(function () {
            $now = now();

            /*
             * Written if missing: this can run before the `modules:sync` that
             * follows it on a deploy, and a row absent here would silently be
             * a module that organization lost. Sync fills in the rest.
             */
            foreach (self::MODULES as $key => $name) {
                DB::table('modules')->insertOrIgnore([
                    'key' => $key,
                    'name' => $name,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $moduleIds = DB::table('modules')->whereIn('key', array_keys(self::MODULES))->pluck('id');

            foreach (DB::table('organizations')->pluck('id') as $organizationId) {
                foreach ($moduleIds as $moduleId) {
                    DB::table('organization_modules')->insertOrIgnore([
                        'organization_id' => $organizationId,
                        'module_id' => $moduleId,
                        'is_enabled' => true,
                        'reason' => self::REASON,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }

            foreach (DB::table('plans')->where('is_system', false)->pluck('id') as $planId) {
                foreach ($moduleIds as $moduleId) {
                    DB::table('plan_modules')->insertOrIgnore([
                        'plan_id' => $planId,
                        'module_id' => $moduleId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        });

        if (Schema::hasColumn('modules', 'is_core')) {
            Schema::table('modules', function (Blueprint $table) {
                $table->dropColumn('is_core');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('modules', 'is_core')) {
            Schema::table('modules', function (Blueprint $table) {
                $table->boolean('is_core')->default(false);
            });
        }

        DB::transaction(function () {
            $moduleIds = DB::table('modules')->whereIn('key', array_keys(self::MODULES))->pluck('id');

            DB::table('modules')->whereIn('id', $moduleIds)->update(['is_core' => true]);

            DB::table('organization_modules')->whereIn('module_id', $moduleIds)->delete();
            DB::table('plan_modules')->whereIn('module_id', $moduleIds)->delete();
        });
    }
};
