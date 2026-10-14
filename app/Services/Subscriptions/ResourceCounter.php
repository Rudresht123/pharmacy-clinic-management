<?php

namespace App\Services\Subscriptions;

use App\Models\Platform\Organization;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Location;
use App\Models\Tenant\User;
use App\Services\Tenancy\TenantConnectionService;
use App\Support\Subscriptions\Entitlements;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * How much of each limited resource an organization is using, counted live
 * from its own database.
 *
 * WHAT COUNTS — the policy, in one place:
 *
 *   branches   `locations` that are active and not removed.
 *   doctors    `doctors` that are active and not removed — ONE per doctor.
 *              A doctor posted to three branches is one row here and three in
 *              `doctor_locations`; the plan counts unique doctors, so the
 *              postings are never counted.
 *   employees  staff logins that are active and not removed — not the owner
 *              (the account holder is not an employee of themselves), not a
 *              patient's portal login, and not a doctor's own login (that
 *              doctor is already counted once, under doctors).
 *
 * Soft-deleted and deactivated records never use capacity: the models'
 * SoftDeletes scope drops the first, `is_active` the second.
 *
 * Counts only — no patient record is read, and nothing leaves the tenant
 * database but a number.
 */
class ResourceCounter
{
    public function __construct(
        private readonly TenantConnectionService $tenants,
    ) {}

    public function count(Organization $organization, string $resource): int
    {
        if (! Entitlements::isResource($resource)) {
            throw new \InvalidArgumentException("Not a counted resource: {$resource}");
        }

        // An organization still being provisioned has nothing to count.
        if ($organization->database_name === null) {
            return 0;
        }

        return (int) $this->onTenant($organization, fn () => $this->query($resource)->count());
    }

    /** @return array<string, int> every resource's count */
    public function all(Organization $organization): array
    {
        if ($organization->database_name === null) {
            return array_fill_keys(Entitlements::RESOURCES, 0);
        }

        return $this->onTenant($organization, function () {
            $counts = [];

            foreach (Entitlements::RESOURCES as $resource) {
                $counts[$resource] = $this->query($resource)->count();
            }

            return $counts;
        });
    }

    /**
     * Which limit this one record counts toward, if any — the same rules as
     * query() below, asked of a single model (ResourceLimitObserver).
     */
    public static function countsToward(Model $model): ?string
    {
        if ($model->getAttribute('deleted_at') !== null) {
            return null;
        }

        // A column left to its database default (true) reads as null here.
        $active = (bool) ($model->getAttribute('is_active') ?? true);

        return match (true) {
            $model instanceof Location => $active ? Entitlements::BRANCHES : null,
            $model instanceof Doctor => $active ? Entitlements::DOCTORS : null,
            $model instanceof User => $active
                && $model->getAttribute('role') === User::STAFF
                && $model->getAttribute('userable_type') !== Doctor::class
                    ? Entitlements::EMPLOYEES
                    : null,
            default => null,
        };
    }

    /** The query that defines what counts, on the connected tenant. */
    public function query(string $resource)
    {
        $connection = TenantConnectionService::CONNECTION;

        return match ($resource) {
            Entitlements::BRANCHES => Location::on($connection)->where('is_active', true),

            Entitlements::DOCTORS => Doctor::on($connection)->where('is_active', true),

            Entitlements::EMPLOYEES => User::on($connection)
                ->where('is_active', true)
                ->where('role', User::STAFF)
                ->where(fn ($q) => $q->whereNull('userable_type')->orWhere('userable_type', '!=', Doctor::class)),

            default => throw ValidationException::withMessages(['resource' => 'Unknown resource.']),
        };
    }

    /**
     * Run against this organization's database — in place when the request
     * is already connected to it, otherwise connecting and restoring.
     */
    private function onTenant(Organization $organization, \Closure $callback): mixed
    {
        if ($this->tenants->current() === $organization->database_name) {
            return $callback();
        }

        return $this->tenants->run($organization->database_name, $callback);
    }
}
