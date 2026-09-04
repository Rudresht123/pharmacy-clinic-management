<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        /*
         * Roles first — PlatformUserSeeder attaches one to the admin it
         * creates.
         *
         * There is deliberately nothing here that writes to the central
         * `users` table. Administrators live in platform_users; tenant users
         * live in their own tenant database and are created by the
         * organization setup flow. The old AdminUserSeeder seeded a `users`
         * row with the same address and password as the admin, which meant
         * one set of credentials opened two different accounts on two
         * different guards — exactly the blur §18 exists to prevent.
         */
        $this->call([
            PlatformRoleSeeder::class,
            PlatformUserSeeder::class,
            // The module catalogue an organization's entitlements point at.
            ModuleSeeder::class,
        ]);
    }
}
