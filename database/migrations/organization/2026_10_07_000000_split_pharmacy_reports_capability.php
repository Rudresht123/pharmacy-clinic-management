<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Splits the six pharmacy reports out from under `pharmacy.view`.
 *
 * Sales, Purchases, Stock, Expiry, Profit and GST used to open on the same
 * capability as the dashboard, the stock list and the settings screen — so
 * there was no way to hand somebody the sales report without also handing
 * them the shop's margins. Each is now its own capability
 * (`reports.sales` … `reports.gst`), in a module of its own (`reports`,
 * requiring `pharmacy`) that a superadmin binds to an organization
 * separately.
 *
 * NOTHING LOSES POWER HERE. Every role that could already read every report
 * through `pharmacy.view` is given all six of the new keys, so a role that
 * could see the GST report yesterday can see it today — the split only makes
 * it POSSIBLE, from here on, to grant fewer.
 *
 * This is silent about the ORGANIZATION-level module grant. `reports` still
 * has to be sold to the organization (`organization_modules`) before any of
 * these six keys resolve to anything — Permission checks entitlement before
 * it ever looks at a role — so a role backfilled here reads correctly the
 * moment a superadmin switches Reports on, and grants nothing before that,
 * exactly like any other capability whose module has not been bought yet.
 *
 * The DB facade rather than the Role model, for the reason the precedent this
 * mirrors states: a migration has to keep replaying correctly against the
 * schema as it was the day it was written, and a model changes underneath it.
 */
return new class extends Migration
{
    private const NEW_CAPABILITIES = [
        'reports.sales',
        'reports.purchases',
        'reports.stock',
        'reports.expiry',
        'reports.profit',
        'reports.gst',
    ];

    public function up(): void
    {
        $now = now();

        $roleIds = DB::table('role_capabilities')
            ->where('capability', 'pharmacy.view')
            ->pluck('role_id')
            ->unique();

        foreach ($roleIds as $roleId) {
            foreach (self::NEW_CAPABILITIES as $capability) {
                // updateOrInsert rather than insert: safe to replay, and safe
                // if an owner has already ticked one of these by hand between
                // the release and this migration reaching their database.
                DB::table('role_capabilities')->updateOrInsert(
                    ['role_id' => $roleId, 'capability' => $capability],
                    ['updated_at' => $now, 'created_at' => $now],
                );
            }
        }
    }

    /**
     * Lossy, and says so: a role given `reports.profit` by hand after this
     * migration ran, by somebody who never held `pharmacy.view` at all — a
     * head-office role, say — is not this migration's to remove, so only
     * roles CURRENTLY holding `pharmacy.view` lose the six again.
     */
    public function down(): void
    {
        $roleIds = DB::table('role_capabilities')
            ->where('capability', 'pharmacy.view')
            ->pluck('role_id')
            ->unique();

        DB::table('role_capabilities')
            ->whereIn('role_id', $roleIds)
            ->whereIn('capability', self::NEW_CAPABILITIES)
            ->delete();
    }
};
