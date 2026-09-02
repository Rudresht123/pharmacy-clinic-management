<?php

namespace App\Repositories\Tenant\Contracts;

use App\Repositories\Contracts\RepositoryInterface;
use Illuminate\Database\Eloquent\Builder;

interface CustomerRepositoryInterface extends RepositoryInterface
{
    /**
     * The base query for the list screen, with the screen's filters already
     * applied.
     *
     * `registeredLocationId` filters on where somebody signed up. It is a
     * reporting question, never a permission one — every branch still sees
     * every customer.
     */
    public function listing(
        bool $activeOnly = false,
        ?string $status = null,
        ?int $registeredLocationId = null,
    ): Builder;

    /**
     * Totals for the list screen's header, including the split by the branch
     * customers were registered at.
     *
     * @return array<string, mixed>
     */
    public function stats(): array;
}
