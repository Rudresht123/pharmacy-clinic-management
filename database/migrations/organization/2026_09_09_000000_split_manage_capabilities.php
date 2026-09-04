<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Splits the `manage` capabilities into the separate acts they were bundling.
 *
 * `branches.manage` meant add, edit and remove all at once, so an owner who
 * wanted somebody to be able to add a branch had no way to stop them deleting
 * one. Creating, editing and removing are three different decisions and are
 * now three capabilities. The same for staff and customers, and appointments
 * splits along the lines a busy desk actually draws.
 *
 * NOTHING LOSES POWER HERE. Every role holding an old key is given the exact
 * set the old key allowed — that is what the map below is for. A role that
 * could delete a branch yesterday can delete one today; what changed is that
 * the owner can now write a role that could not.
 *
 * The DB facade rather than the Role model is deliberate and is not an
 * exception to the project's Models-not-facade rule: a migration has to keep
 * replaying correctly against the schema as it was the day it was written, and
 * a model changes underneath it.
 */
return new class extends Migration
{
    /**
     * Old key => what it used to allow, as separate capabilities.
     *
     * `customers.remove` and `appointments.book` are renames and widenings
     * rather than pure splits, which is why they are in the same map: the
     * question each answers is "what could somebody holding this do", and the
     * answer has to survive the change whatever shape it takes.
     */
    private const EXPANSIONS = [
        'branches.manage' => ['branches.create', 'branches.edit', 'branches.delete'],
        'people.manage' => ['people.create', 'people.edit', 'people.delete'],
        'customers.manage' => ['customers.create', 'customers.edit'],
        'customers.remove' => ['customers.delete'],

        // Covered the whole queue: booking, moving through it, and cancelling.
        'appointments.book' => ['appointments.book', 'appointments.queue', 'appointments.cancel'],

        // Covered the doctors themselves and their week.
        'appointments.doctors' => ['appointments.doctors', 'appointments.schedule'],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::EXPANSIONS as $old => $replacements) {
            $roleIds = DB::table('role_capabilities')
                ->where('capability', $old)
                ->pluck('role_id')
                ->unique();

            foreach ($roleIds as $roleId) {
                foreach ($replacements as $capability) {
                    /*
                     * updateOrInsert rather than insert: two old keys can map
                     * onto the same new one — `appointments.book` keeps its own
                     * name — and a role holding both would otherwise collide
                     * with the unique index on (role_id, capability).
                     */
                    DB::table('role_capabilities')->updateOrInsert(
                        ['role_id' => $roleId, 'capability' => $capability],
                        ['updated_at' => $now, 'created_at' => $now],
                    );
                }
            }

            // The old key goes only once its replacements are in place, so an
            // interrupted run leaves a role with more than it had rather than
            // less.
            if ($old !== 'appointments.book' && $old !== 'appointments.doctors') {
                DB::table('role_capabilities')->where('capability', $old)->delete();
            }
        }
    }

    public function down(): void
    {
        $now = now();

        foreach (self::EXPANSIONS as $old => $replacements) {
            $roleIds = DB::table('role_capabilities')
                ->whereIn('capability', $replacements)
                ->pluck('role_id')
                ->unique();

            foreach ($roleIds as $roleId) {
                DB::table('role_capabilities')->updateOrInsert(
                    ['role_id' => $roleId, 'capability' => $old],
                    ['updated_at' => $now, 'created_at' => $now],
                );
            }

            /*
             * Rolling back is lossy and says so: a role given only
             * `branches.create` after the split has no narrower old key to go
             * back to, so it lands on `branches.manage` and gains the delete
             * it never had. There is no honest alternative — the old vocabulary
             * cannot express what the new one can.
             */
            DB::table('role_capabilities')
                ->whereIn('capability', array_diff($replacements, [$old]))
                ->delete();
        }
    }
};
