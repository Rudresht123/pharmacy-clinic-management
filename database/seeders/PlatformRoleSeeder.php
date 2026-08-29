<?php

namespace Database\Seeders;

use App\Models\Platform\PlatformRole;
use Illuminate\Database\Seeder;

/**
 * The five panel roles from Build Spec §18.
 *
 * Idempotent — matched on `code`, so re-running updates the display text
 * without duplicating rows or detaching anyone's role.
 */
class PlatformRoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            [
                'code' => PlatformRole::SUPER_ADMIN,
                'name' => 'Super Admin',
                'description' => 'Everything, including hard delete.',
                'sort_order' => 1,
            ],
            [
                'code' => PlatformRole::OPS,
                'name' => 'Ops',
                'description' => 'Provisioning, migrations and health. Cannot delete.',
                'sort_order' => 2,
            ],
            [
                'code' => PlatformRole::SUPPORT,
                'name' => 'Support',
                'description' => 'Read organizations and impersonate read-only.',
                'sort_order' => 3,
            ],
            [
                'code' => PlatformRole::BILLING,
                'name' => 'Billing',
                'description' => 'Plans, subscriptions and limits.',
                'sort_order' => 4,
            ],
            [
                'code' => PlatformRole::CATALOG,
                'name' => 'Catalog',
                'description' => 'Global manufacturers and products only.',
                'sort_order' => 5,
            ],
        ];

        foreach ($roles as $role) {
            PlatformRole::updateOrCreate(
                ['code' => $role['code']],
                $role
            );
        }

        $this->command?->info('Seeded '.count($roles).' platform roles.');
    }
}
