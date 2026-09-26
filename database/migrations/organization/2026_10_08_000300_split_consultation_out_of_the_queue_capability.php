<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Takes the consultation out of the receptionist's key.
 *
 * `appointments.queue` was "Check in, call through and complete" — one
 * capability covering the desk's whole day AND both ends of a doctor's
 * consultation. Every role that ran a queue could therefore start and finish
 * a clinical record, which is why the receptionist's screen carried a Done
 * button.
 *
 * It is now three keys: `appointments.queue` for the desk, and
 * `appointments.consult_start` / `appointments.consult_complete` for the
 * doctor.
 *
 * THIS IS A NARROWING, AND IT IS THE POINT — so unlike the earlier capability
 * split, it deliberately does NOT hand every holder of the old key the new
 * ones. A receptionist who could complete a consultation yesterday cannot
 * today. That is the change, not a regression.
 *
 * WHO KEEPS IT, then. Two tests, and a role passing either gets the pair:
 *
 *   1. It came from the Doctor template (`slug = 'doctor'`, which is what
 *      DefaultRoleSeeder writes). The seeded case, and the one nearly every
 *      tenant is.
 *
 *   2. It holds `prescriptions.write`. The hand-written case. Writing a
 *      prescription is something only a clinician does — the pharmacist
 *      dispenses under `pharmacy.dispense` and the desk holds neither — so a
 *      role with it is a clinical role whatever its owner called it. Getting
 *      this wrong in the cautious direction means a doctor is briefly unable
 *      to start a consultation and an owner ticks one box; getting it wrong
 *      the other way would silently re-grant the desk the authority this
 *      whole change removes.
 *
 * Owners are unaffected either way: they bypass roles entirely.
 *
 * The DB facade rather than the Role model is deliberate and is not an
 * exception to the project's Models-not-facade rule: a migration has to keep
 * replaying correctly against the schema as it was the day it was written,
 * and a model changes underneath it.
 */
return new class extends Migration
{
    private const GRANTED = [
        'appointments.consult_start',
        'appointments.consult_complete',
    ];

    public function up(): void
    {
        $now = now();

        $clinical = DB::table('roles')
            ->where('slug', 'doctor')
            ->pluck('id');

        $prescribers = DB::table('role_capabilities')
            ->where('capability', 'prescriptions.write')
            ->pluck('role_id');

        /*
         * Only roles that actually ran a queue. A Doctor-template role in a
         * tenant that never bought appointments holds nothing from that
         * module, and handing it two consultation keys would put capabilities
         * on a role for a module the organization was never sold — which
         * `Permission::capabilitiesFor()` would intersect away anyway, but
         * which would read as a lie on the role screen.
         */
        $hadTheQueue = DB::table('role_capabilities')
            ->where('capability', 'appointments.queue')
            ->pluck('role_id');

        $roleIds = $clinical
            ->merge($prescribers)
            ->intersect($hadTheQueue)
            ->unique();

        foreach ($roleIds as $roleId) {
            foreach (self::GRANTED as $capability) {
                DB::table('role_capabilities')->updateOrInsert(
                    ['role_id' => $roleId, 'capability' => $capability],
                    ['updated_at' => $now, 'created_at' => $now],
                );
            }
        }
    }

    /**
     * Rolling back folds the two keys away again.
     *
     * Lossy and honest about it: the old vocabulary had no way to say "may
     * run a queue but may not complete a consultation", so everything that
     * held either new key goes back to holding only `appointments.queue`,
     * which meant all three.
     */
    public function down(): void
    {
        $now = now();

        $roleIds = DB::table('role_capabilities')
            ->whereIn('capability', self::GRANTED)
            ->pluck('role_id')
            ->unique();

        foreach ($roleIds as $roleId) {
            DB::table('role_capabilities')->updateOrInsert(
                ['role_id' => $roleId, 'capability' => 'appointments.queue'],
                ['updated_at' => $now, 'created_at' => $now],
            );
        }

        DB::table('role_capabilities')->whereIn('capability', self::GRANTED)->delete();
    }
};
