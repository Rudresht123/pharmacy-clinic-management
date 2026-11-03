<?php

namespace App\Services\Queue;

use App\Services\Permissions\Permission;
use App\Services\Tenancy\CurrentTenant;

/**
 * Whether the Smart Queue runs at a branch: sold to this organization, and not
 * opted out of here.
 *
 * Routes ask through `module:queue`. This is for the code underneath them that
 * every clinic runs, like check-in, which has to behave differently where the
 * module is on and exactly as it always did where it is not.
 */
class QueueModule
{
    public const KEY = 'queue';

    public function __construct(
        private readonly Permission $permission,
    ) {}

    public function runsAt(?int $locationId): bool
    {
        $organization = CurrentTenant::organization();

        return $organization !== null && $this->permission->hasModule($organization, self::KEY, $locationId);
    }
}
