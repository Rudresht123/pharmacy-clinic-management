<?php

namespace App\Observers;

use App\Models\Platform\Organization;
use App\Services\Subscriptions\ResourceCounter;
use App\Services\Subscriptions\ResourceLimiter;
use App\Services\Tenancy\TenantConnectionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request;

/**
 * The plan's branch, doctor and employee limits, enforced on the models.
 *
 * On the model rather than in each controller, because a record can start to
 * count in more ways than one screen: created on the form, created by the
 * branch-admin provisioner, switched back on after being deactivated,
 * restored from the bin. Every one of them fires `creating` or `updating` (a
 * restore saves the row, so it arrives as an update whose original was
 * deleted), so every one of them is asked.
 *
 * Only a change that makes a record START counting is checked — editing a
 * doctor's phone number is not "adding a doctor". An organization already
 * over its limit (it moved to a smaller plan) keeps every record it has; it
 * just cannot add or reactivate one until it is under.
 *
 * Unlimited plans (Complimentary among them) return before anything is
 * counted.
 */
class ResourceLimitObserver
{
    public function creating(Model $model): void
    {
        $this->check($model, wasCounting: false);
    }

    public function updating(Model $model): void
    {
        $original = (clone $model)->setRawAttributes($model->getRawOriginal());

        $this->check($model, wasCounting: ResourceCounter::countsToward($original) !== null);
    }

    private function check(Model $model, bool $wasCounting): void
    {
        $resource = ResourceCounter::countsToward($model);

        if ($resource === null || $wasCounting) {
            return;
        }

        $organization = $this->organization();

        if ($organization === null) {
            return;
        }

        $limiter = app(ResourceLimiter::class);
        $connection = $model->getConnection();

        // The guard's advisory lock lives as long as a transaction. The create
        // paths all run in one; anything that does not is still checked, but
        // without the lock spanning its insert.
        if ($connection->transactionLevel() > 0) {
            $limiter->guard($organization, $resource);

            return;
        }

        $connection->transaction(fn () => $limiter->guard($organization, $resource));
    }

    /** Whose database this is — the request's organization, or by name. */
    private function organization(): ?Organization
    {
        if (app()->bound('request')) {
            $organization = Request::instance()->attributes->get('tenant.organization');

            if ($organization instanceof Organization) {
                return $organization;
            }
        }

        $database = app(TenantConnectionService::class)->current();

        return $database === null ? null : Organization::query()->where('database_name', $database)->first();
    }
}
