<?php

namespace App\Repositories\Platform\Contracts;

use App\Models\Platform\PlatformUser;
use App\Repositories\Contracts\RepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * Everything the panel needs to ask about its own administrators.
 *
 * Extends the shared vocabulary with the few questions that only make sense
 * for platform users.
 */
interface PlatformUserRepositoryInterface extends RepositoryInterface
{
    public function findByEmail(string $email): ?PlatformUser;

    public function findByUuid(string $uuid): ?PlatformUser;

    /** Administrators who can currently sign in, with their roles loaded. */
    public function activeWithRoles(): Collection;

    /**
     * Create an administrator and give them roles in one step, so a caller
     * can never leave a new admin with no role at all.
     *
     * @param  array<int, string>  $roleCodes
     */
    public function createWithRoles(array $attributes, array $roleCodes): PlatformUser;

    /** @param  array<int, string>  $roleCodes */
    public function syncRoles(PlatformUser $user, array $roleCodes): PlatformUser;

    public function recordLogin(PlatformUser $user, ?string $ip): void;
}
