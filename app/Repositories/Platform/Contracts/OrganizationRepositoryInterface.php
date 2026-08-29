<?php

namespace App\Repositories\Platform\Contracts;

use App\Models\Platform\Organization;
use App\Repositories\Contracts\RepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

interface OrganizationRepositoryInterface extends RepositoryInterface
{
    /** URLs carry the ULID, so this is how a request finds its record. */
    public function findByUuid(string $uuid): ?Organization;

    public function findByUuidOrFail(string $uuid): Organization;

    public function findBySlug(string $slug): ?Organization;

    /**
     * The list screen's base query, with the optional filters applied.
     *
     * @param  array{status?: string|null, organization_type_id?: int|null, is_active?: bool|null}  $filters
     */
    public function listing(array $filters = []): Builder;

    /**
     * Move an organization to a new status and record why.
     *
     * The status column and the history row are written together — a status
     * that changed with no history behind it is the thing this exists to
     * prevent.
     */
    public function changeStatus(
        Organization $organization,
        string $status,
        ?string $reason = null,
        ?int $changedBy = null,
    ): Organization;

    /** How many organizations sit in each status, for the dashboard. */
    public function countsByStatus(): Collection;
}
