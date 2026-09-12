<?php

namespace App\Repositories\Tenant;

use App\Models\Tenant\Medicine;
use App\Repositories\BaseRepository;
use App\Repositories\Tenant\Contracts\MedicineRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;

class MedicineRepository extends BaseRepository implements MedicineRepositoryInterface
{
    /** Compares the columns the way normalise() compares the input. */
    private const NORMALISED = "regexp_replace(lower(coalesce(%s, '')), '[^[:alnum:]]+', '', 'g')";

    public function __construct(Medicine $model)
    {
        parent::__construct($model);
    }

    public function listing(?string $status = null, array $filters = []): Builder
    {
        return $this->query()
            ->when($status === 'active', fn (Builder $query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn (Builder $query) => $query->where('is_active', false))
            ->when(
                filled($filters['dosage_form'] ?? null),
                fn (Builder $query) => $query->where('dosage_form', $filters['dosage_form'])
            )
            ->when(
                filled($filters['schedule'] ?? null),
                fn (Builder $query) => $query->where('schedule', $filters['schedule'])
            )
            ->when(
                filled($filters['category'] ?? null),
                fn (Builder $query) => $query->where('category', 'ILIKE', $filters['category'])
            );
    }

    public function removed(): Builder
    {
        return $this->query()->onlyTrashed()->with('remover');
    }

    public function findDuplicate(array $identity, ?int $ignoreId = null): ?Medicine
    {
        $query = $this->query()->where('dosage_form', $identity['dosage_form'] ?? null);

        foreach (['generic_name', 'brand_name', 'strength', 'manufacturer'] as $column) {
            $query->whereRaw(
                sprintf(self::NORMALISED, $column).' = ?',
                [$this->normalise($identity[$column] ?? null)],
            );
        }

        return $query
            ->when($ignoreId !== null, fn (Builder $query) => $query->whereKeyNot($ignoreId))
            ->first();
    }

    public function findByCode(string $code, ?int $ignoreId = null): ?Medicine
    {
        return $this->query()
            ->whereRaw('lower(medicine_code) = ?', [mb_strtolower(trim($code))])
            ->when($ignoreId !== null, fn (Builder $query) => $query->whereKeyNot($ignoreId))
            ->first();
    }

    /** "Paracetamol 500 mg" → "paracetamol500mg", as the SQL above does. */
    private function normalise(mixed $value): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower((string) $value));
    }
}
