<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lets the receptionists who already have a role use the desk's own messages.
 *
 * `appointments.messages_desk` is new, and the receptionist template now
 * includes it — but a template only writes a role when it is first seeded.
 * Without this, nobody already set up as a receptionist would see it until
 * something unrelated rewrote their role.
 *
 * WHO GETS IT: the shared 'receptionist' role (by slug) that still holds
 * `appointments.queue` — still doing the desk's job, in other words. An owner
 * who took that away has said what the role is for, and this does not hand
 * anything back.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $roleIds = DB::table('roles')
            ->where('slug', 'receptionist')
            ->whereIn('id', DB::table('role_capabilities')->where('capability', 'appointments.queue')->select('role_id'))
            ->pluck('id');

        foreach ($roleIds as $roleId) {
            DB::table('role_capabilities')->updateOrInsert(
                ['role_id' => $roleId, 'capability' => 'appointments.messages_desk'],
                ['updated_at' => $now, 'created_at' => $now],
            );
        }
    }

    public function down(): void
    {
        DB::table('role_capabilities')
            ->whereIn('role_id', DB::table('roles')->where('slug', 'receptionist')->select('id'))
            ->where('capability', 'appointments.messages_desk')
            ->delete();
    }
};
