<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Opens the new Finance screen to the roles that were always meant to read it.
 *
 * `billing.finance` is a new capability, and the role templates now give it to
 * the branch manager, the accountant and head office. But DefaultRoleSeeder
 * only ever ADDS roles — it never edits one that exists — so in every
 * organization set up before this release those three roles would not have
 * it, and nobody but the owner would ever see the screen.
 *
 * WHO GETS IT: a role that came from one of those three templates (by slug,
 * which is what DefaultRoleSeeder writes, and unique per tenant) AND still
 * reads bills (`billing.view`). An owner who took billing away from their
 * accountant has said that role reads no money; this does not hand it back.
 *
 * Hand-written roles are left alone, deliberately. Finance shows the
 * pharmacy's takings next to the OPD's — a cashier who takes OPD payments has
 * no business reading the shop's — so guessing wrong here leaks money figures,
 * while guessing cautiously costs an owner one tick on the Roles screen.
 * Owners are unaffected either way: they bypass roles entirely.
 *
 * A BRANCH'S DECISION CARRIES OVER. A branch that took `billing.view` away
 * from one of these roles (`branch_role_capabilities`) has said that role
 * reads no bills there; the finance screen is bills, so it is taken away
 * there too, in the same sparse-subtraction shape.
 *
 * The DB facade rather than the models, for the reason its precedents give:
 * a migration has to keep replaying correctly against the schema as it was
 * the day it was written, and a model changes underneath it.
 */
return new class extends Migration
{
    private const CAPABILITY = 'billing.finance';

    /** The templates that carry the key, frozen as they were on the day. */
    private const TEMPLATES = ['branch-manager', 'accountant', 'head-office'];

    public function up(): void
    {
        $now = now();

        $roleIds = DB::table('roles')
            ->whereIn('slug', self::TEMPLATES)
            ->whereIn('id', DB::table('role_capabilities')->where('capability', 'billing.view')->select('role_id'))
            ->pluck('id');

        foreach ($roleIds as $roleId) {
            // updateOrInsert: safe to replay, and safe if an owner ticked it by hand first.
            DB::table('role_capabilities')->updateOrInsert(
                ['role_id' => $roleId, 'capability' => self::CAPABILITY],
                ['updated_at' => $now, 'created_at' => $now],
            );
        }

        $clipped = DB::table('branch_role_capabilities')
            ->whereIn('role_id', $roleIds)
            ->where('capability', 'billing.view')
            ->get(['location_id', 'role_id', 'created_by']);

        foreach ($clipped as $row) {
            DB::table('branch_role_capabilities')->updateOrInsert(
                ['location_id' => $row->location_id, 'role_id' => $row->role_id, 'capability' => self::CAPABILITY],
                ['created_by' => $row->created_by, 'updated_at' => $now, 'created_at' => $now],
            );
        }
    }

    /**
     * Lossy, and says so: the key comes off these three roles even where an
     * owner ticked it by hand after this ran. Hand-written roles never got it
     * from here, so they keep whatever they were given.
     */
    public function down(): void
    {
        $roleIds = DB::table('roles')->whereIn('slug', self::TEMPLATES)->pluck('id');

        DB::table('branch_role_capabilities')
            ->whereIn('role_id', $roleIds)
            ->where('capability', self::CAPABILITY)
            ->delete();

        DB::table('role_capabilities')
            ->whereIn('role_id', $roleIds)
            ->where('capability', self::CAPABILITY)
            ->delete();
    }
};
