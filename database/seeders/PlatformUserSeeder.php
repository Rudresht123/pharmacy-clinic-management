<?php

namespace Database\Seeders;

use App\Models\Platform\PlatformRole;
use App\Repositories\Platform\Contracts\PlatformUserRepositoryInterface;
use Illuminate\Database\Seeder;

/**
 * The first Super Admin.
 *
 * §18 forbids self-registration, so the panel needs one administrator to
 * exist before anyone can sign in and create the rest. This seeder is that
 * bootstrap and nothing more.
 */
class PlatformUserSeeder extends Seeder
{
    public function __construct(
        private readonly PlatformUserRepositoryInterface $admins,
    ) {}

    public function run(): void
    {
        $email = env('PLATFORM_ADMIN_EMAIL', 'rudershtiwari8@gmail.com');
        $password = env('PLATFORM_ADMIN_PASSWORD', 'Admin@123');
        $name = env('PLATFORM_ADMIN_NAME', 'Rudresh Tiwari');

        if ($existing = $this->admins->findByEmail($email)) {
            // Re-running must not silently reset a password that has since
            // been changed; only the role assignment is repaired.
            $this->admins->syncRoles($existing, [PlatformRole::SUPER_ADMIN]);

            $this->command?->warn("Platform admin {$email} already exists — roles re-synced, password left alone.");

            return;
        }

        $this->admins->createWithRoles(
            [
                'name' => $name,
                'email' => $email,
                'password' => $password, // hashed by the model cast
                'is_active' => true,
            ],
            [PlatformRole::SUPER_ADMIN]
        );

        $this->command?->info("Created Super Admin {$email}");
        $this->command?->warn('Change this password after the first sign-in.');
    }
}
