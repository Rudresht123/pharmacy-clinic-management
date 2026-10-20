<?php

namespace App\Services\Exports\Datasets\Concerns;

use App\Models\Tenant\Customer;
use Illuminate\Database\Eloquent\Builder;

/**
 * The three patient columns most exports carry — name, UHID, mobile — and
 * the search that reaches them across the relation.
 *
 * A counter sale or a hand-made bill may have no registered patient at all,
 * only a name and a number typed at the till, which is why each falls back
 * to the walk-in fields rather than leaving the row nameless.
 */
trait ReadsPatients
{
    /** @return array{patient: string, uhid: ?string, mobile: ?string} */
    protected function patientCells(?Customer $patient, ?string $walkInName = null, ?string $walkInPhone = null): array
    {
        return [
            'patient' => $patient?->name ?? ($walkInName ?: 'Walk-in'),
            'uhid' => $patient?->code,
            'mobile' => $patient?->phone ?? $walkInPhone,
        ];
    }

    /** "Female, 34 yrs" — the line under a patient's name on screen. */
    protected function patientLine(?Customer $patient): ?string
    {
        if ($patient === null) {
            return null;
        }

        $age = $patient->age();
        $line = collect([
            $patient->gender ? ucfirst($patient->gender) : null,
            $age !== null ? "{$age} yrs" : null,
        ])->filter()->implode(', ');

        return $line !== '' ? $line : null;
    }

    /** Rows whose patient's name, UHID or mobile matches — through `$relation`. */
    protected function matchingPatient(Builder $query, string $relation, string $like): Builder
    {
        return $query->orWhereHas($relation, fn (Builder $patient) => $patient
            ->where('name', 'ILIKE', $like)
            ->orWhere('code', 'ILIKE', $like)
            ->orWhere('phone', 'ILIKE', $like));
    }

    /** The relation's columns the cells, their second line and the photo read, for an eager load. */
    protected function patientFields(): string
    {
        return 'id,name,code,phone,gender,date_of_birth,photo';
    }
}
