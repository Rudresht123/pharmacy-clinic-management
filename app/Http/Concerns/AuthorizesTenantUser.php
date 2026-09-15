<?php

namespace App\Http\Concerns;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Asks a Policy about the signed-in tenant user.
 *
 * Named explicitly because the application's default guard is the
 * platform's: a bare Gate call or $this->authorize() would put the question
 * to the wrong person (or to nobody) and answer 403 for everyone.
 */
trait AuthorizesTenantUser
{
    /**
     * @param  mixed  $arguments  the model, or [ModelClass, ...extra] for abilities like `create`
     */
    protected function authorizeTenant(string $ability, mixed $arguments): void
    {
        Gate::forUser(Auth::guard('web')->user())->authorize($ability, $arguments);
    }

    protected function tenantMay(string $ability, mixed $arguments): bool
    {
        return Gate::forUser(Auth::guard('web')->user())->allows($ability, $arguments);
    }
}
