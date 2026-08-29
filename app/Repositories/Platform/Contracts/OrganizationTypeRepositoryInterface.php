<?php

namespace App\Repositories\Platform\Contracts;

use App\Models\Platform\OrganizationType;
use App\Repositories\Contracts\RepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

interface OrganizationTypeRepositoryInterface extends RepositoryInterface
{
    /** Active types, alphabetical — what a form's dropdown needs. */
    public function activeOrdered(): Collection;

    /**
     * The base query for the list screen, with the optional
     * "active only" filter already applied.
     */
    public function listing(bool $activeOnly = false): Builder;

    /**
     * Whether any organization still points at this type.
     *
     * Asked before deleting, so a type in use cannot be removed out from
     * under the organizations that reference it.
     */
    public function isInUse(OrganizationType $type): bool;

    public function toggleStatus(OrganizationType $type): OrganizationType;
}
