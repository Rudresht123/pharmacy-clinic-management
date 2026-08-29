<?php

namespace App\Providers;

use App\Repositories\Platform\Contracts\OrganizationRepositoryInterface;
use App\Repositories\Platform\Contracts\OrganizationTypeRepositoryInterface;
use App\Repositories\Platform\Contracts\PlatformUserRepositoryInterface;
use App\Repositories\Platform\OrganizationRepository;
use App\Repositories\Platform\OrganizationTypeRepository;
use App\Repositories\Platform\PlatformUserRepository;
use Illuminate\Support\ServiceProvider;

/**
 * Wires each repository interface to the class that implements it.
 *
 * This is the only file that knows which implementation is in use, so
 * swapping one — for a cached variant, or a fake in a test — is a one-line
 * change here rather than an edit in every controller.
 *
 * Add new bindings to the map below; nothing else needs to change.
 */
class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * Interface => implementation.
     *
     * @var array<class-string, class-string>
     */
    private const BINDINGS = [
        PlatformUserRepositoryInterface::class => PlatformUserRepository::class,
        OrganizationRepositoryInterface::class => OrganizationRepository::class,
        OrganizationTypeRepositoryInterface::class => OrganizationTypeRepository::class,
    ];

    public function register(): void
    {
        foreach (self::BINDINGS as $contract => $implementation) {
            $this->app->bind($contract, $implementation);
        }
    }

    /**
     * @return array<int, string>
     */
    public function provides(): array
    {
        return array_keys(self::BINDINGS);
    }
}
