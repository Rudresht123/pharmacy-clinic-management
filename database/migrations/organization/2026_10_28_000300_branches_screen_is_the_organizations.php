<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Branches screen is the organization's, not a branch's.
 *
 * `branches.view/create/edit/delete` are organization-scoped now. Every branch
 * role held `branches.view` only because each branch dropdown read the same
 * list — and with it came the Locations menu entry. The dropdowns no longer
 * need any tick (they read the branches the person works at), so the keys
 * come off every branch-scoped role. Organization-scoped roles — head office,
 * the doctor role, anything an owner wrote at that scope — keep what they hold.
 *
 * The DB facade rather than the models: a migration has to keep replaying
 * against the schema as it was on the day it was written.
 */
return new class extends Migration
{
    private const KEYS = ['branches.view', 'branches.create', 'branches.edit', 'branches.delete'];

    public function up(): void
    {
        DB::table('role_capabilities')
            ->whereIn('capability', self::KEYS)
            ->whereIn('role_id', DB::table('roles')->where('scope', 'branch')->select('id'))
            ->delete();
    }

    public function down(): void
    {
        // Not put back: which branch roles held which of the four is not
        // recorded anywhere once removed, and guessing would widen access.
    }
};
