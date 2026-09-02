<?php

namespace App\Repositories\Tenant\Contracts;

use App\Repositories\Contracts\RepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The first tenant-side repository. Everything it touches lives in whichever
 * tenant database ResolveTenantFromSession selected for this request.
 */
interface LocationRepositoryInterface extends RepositoryInterface
{
    /**
     * The base query for the list screen, with the optional type and
     * "active only" filters already applied.
     *
     * Returns a Builder rather than a paginator so HandlesTableQueries can
     * still layer search and sorting over it.
     */
    public function listing(?string $type = null, bool $activeOnly = false): Builder;

    /** Active locations, alphabetical — what a form's dropdown needs. */
    public function activeOrdered(): Collection;
}
