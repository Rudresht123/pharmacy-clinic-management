<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lets the people already running the desk answer reschedule requests.
 *
 * `appointments.reschedule` is new, and RoleTemplates now includes it for the
 * Receptionist and Branch Manager roles — but those roles were written when
 * the organization was set up. Without this, every existing clinic's patients
 * could ask to move a booking and nobody at the desk could answer.
 *
 * WHO GETS IT: the shared role (by slug) that still books appointments. An
 * owner who took booking away from a role has said what it is for, and this
 * does not hand anything back.
 *
 * The DB facade rather than the models: a migration has to keep replaying
 * against the schema as it was on the day it was written.
 */
return new class extends Migration
{
    /** @var array<string, array{0: string, 1: string}> slug => [needs, grants] */
    private const GRANTS = [
        'receptionist' => ['appointments.book', 'appointments.reschedule'],
        'branch-manager' => ['appointments.book', 'appointments.reschedule'],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::GRANTS as $slug => [$needs, $grants]) {
            $roleIds = DB::table('roles')
                ->where('slug', $slug)
                ->whereIn('id', DB::table('role_capabilities')->where('capability', $needs)->select('role_id'))
                ->pluck('id');

            foreach ($roleIds as $roleId) {
                DB::table('role_capabilities')->updateOrInsert(
                    ['role_id' => $roleId, 'capability' => $grants],
                    ['updated_at' => $now, 'created_at' => $now],
                );
            }
        }
    }

    public function down(): void
    {
        foreach (self::GRANTS as $slug => [, $grants]) {
            DB::table('role_capabilities')
                ->whereIn('role_id', DB::table('roles')->where('slug', $slug)->select('id'))
                ->where('capability', $grants)
                ->delete();
        }
    }
};
