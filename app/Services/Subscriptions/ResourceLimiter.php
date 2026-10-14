<?php

namespace App\Services\Subscriptions;

use App\Models\Platform\Organization;
use App\Services\Tenancy\TenantConnectionService;
use App\Support\Subscriptions\Entitlements;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Refuses one branch, doctor or employee too many — under a lock, so two
 * requests cannot both take the last place.
 *
 * Call it INSIDE the tenant transaction that creates (or reactivates, or
 * restores) the record. It takes a transaction-scoped advisory lock named for
 * the resource on the tenant database, then counts. A second request doing
 * the same waits on the lock until the first commits — and then counts the
 * row the first one added. Without the lock both would count the same number,
 * both would pass, and the limit would be one short of meaning anything.
 *
 * Over the limit it throws a 422 on `limit`, which every existing form
 * already shows beside its fields.
 */
class ResourceLimiter
{
    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly ResourceCounter $counter,
    ) {}

    /**
     * Assert there is room for `$adding` more. Must run inside a transaction
     * on the tenant connection — the lock lives as long as that transaction.
     */
    public function guard(Organization $organization, string $resource, int $adding = 1): void
    {
        if (! Entitlements::isResource($resource)) {
            throw new \InvalidArgumentException("Not a limited resource: {$resource}");
        }

        $limit = $this->entitlements->getResourceLimit($organization, $resource);

        if ($limit === null) {
            return;
        }

        $connection = DB::connection(TenantConnectionService::CONNECTION);

        if ($connection->transactionLevel() < 1) {
            throw new \LogicException('ResourceLimiter::guard() must run inside the tenant transaction that creates the record.');
        }

        $connection->select('SELECT pg_advisory_xact_lock(hashtext(?))', ["resource-limit:{$resource}"]);

        $used = $this->counter->query($resource)->count();

        if ($used + $adding > $limit) {
            $label = strtolower(Entitlements::label($resource));

            throw ValidationException::withMessages([
                'limit' => "Your plan allows {$limit} {$label}, and you have {$used}. Upgrade your plan or buy extra capacity to add more.",
            ]);
        }
    }

    /**
     * Run `$create` with the guard, in a tenant transaction of its own — for
     * a create path that is not already in one.
     *
     * @template T
     *
     * @param  \Closure(): T  $create
     * @return T
     */
    public function within(Organization $organization, string $resource, \Closure $create, int $adding = 1): mixed
    {
        return DB::connection(TenantConnectionService::CONNECTION)->transaction(function () use ($organization, $resource, $create, $adding) {
            $this->guard($organization, $resource, $adding);

            return $create();
        });
    }
}
