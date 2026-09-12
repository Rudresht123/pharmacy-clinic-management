<?php

namespace App\Repositories\Tenant\Contracts;

use App\Models\Tenant\Medicine;
use App\Repositories\Contracts\RepositoryInterface;
use Illuminate\Database\Eloquent\Builder;

interface MedicineRepositoryInterface extends RepositoryInterface
{
    /**
     * The base query for the list screen, with its filters applied.
     *
     * @param  array<string, mixed>  $filters  dosage_form, schedule, category
     */
    public function listing(?string $status = null, array $filters = []): Builder;

    /** Removed medicines, for the restore screen. */
    public function removed(): Builder;

    /**
     * A live medicine this one would duplicate, if there is one.
     *
     * Compared after normalising case, spacing and punctuation, so
     * "Paracetamol 500mg" and "paracetamol 500 mg" are the same medicine.
     *
     * @param  array<string, mixed>  $identity  the Medicine::IDENTITY values
     */
    public function findDuplicate(array $identity, ?int $ignoreId = null): ?Medicine;

    /** A live medicine already using this code, ignoring case. */
    public function findByCode(string $code, ?int $ignoreId = null): ?Medicine;
}
