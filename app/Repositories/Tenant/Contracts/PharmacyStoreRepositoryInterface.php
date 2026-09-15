<?php

namespace App\Repositories\Tenant\Contracts;

use App\Models\Tenant\PharmacyStore;
use App\Repositories\Contracts\RepositoryInterface;
use Illuminate\Database\Eloquent\Builder;

interface PharmacyStoreRepositoryInterface extends RepositoryInterface
{
    /**
     * The stores at the branches the signed-in person works at, with the
     * list screen's filters applied.
     */
    public function listing(?string $status = null, ?int $locationId = null, ?string $storeType = null): Builder;

    /** Removed stores at those branches, for the restore screen. */
    public function removed(): Builder;

    /** A live store already using this code, ignoring case. */
    public function findByCode(string $code, ?int $ignoreId = null): ?PharmacyStore;

    /** The live default store at a branch, if it has one. */
    public function defaultAt(int $locationId): ?PharmacyStore;
}
