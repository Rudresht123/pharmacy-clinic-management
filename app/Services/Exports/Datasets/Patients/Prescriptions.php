<?php

namespace App\Services\Exports\Datasets\Patients;

use App\Models\Tenant\Prescription;
use App\Models\Tenant\PrescriptionItem;
use App\Services\Exports\Column;
use App\Services\Exports\Dataset;
use App\Services\Exports\Datasets\Concerns\ReadsPatients;
use App\Services\Exports\ExportFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Prescriptions written, one row each, with the medicines on it in one cell. */
class Prescriptions extends Dataset
{
    use ReadsPatients;

    public function key(): string
    {
        return 'patients.prescriptions';
    }

    public function tab(): string
    {
        return 'patients';
    }

    public function label(): string
    {
        return 'Prescriptions';
    }

    public function description(): string
    {
        return 'What each doctor prescribed — the medicines, how often and for how long.';
    }

    public function noun(): string
    {
        return 'prescriptions';
    }

    public function icon(): string
    {
        return 'ti ti-prescription';
    }

    public function module(): ?string
    {
        return 'prescriptions';
    }

    public function dateLabel(): ?string
    {
        return 'Prescribed on';
    }

    public function statuses(): array
    {
        return collect(Prescription::STATUSES)->mapWithKeys(fn (string $status) => [$status => $this->words($status)])->all();
    }

    public function searchHint(): ?string
    {
        return 'Prescription no., patient name, UHID or mobile';
    }

    public function columns(): array
    {
        return [
            new Column('date', 'Date', Column::DATE, 7),
            new Column('number', 'Prescription no.', width: 8),
            new Column('patient', 'Patient', width: 11),
            new Column('uhid', 'UHID', width: 6.5),
            new Column('mobile', 'Mobile', width: 7.5),
            new Column('doctor', 'Doctor', width: 10),
            new Column('branch', 'Branch', width: 10),
            new Column('count', 'Items', Column::NUMBER, 4.5),
            new Column('medicines', 'Medicines', width: 26),
            new Column('valid_until', 'Valid until', Column::DATE, 7),
            new Column('status', 'Status', width: 7),
        ];
    }

    public function query(ExportFilters $filters): Builder
    {
        $query = Prescription::query()->with([
            'patient:'.$this->patientFields(),
            'doctor:id,name',
            'location:id,name',
            'items',
        ]);

        $this->atBranches($query, 'location_id', $filters);
        $this->between($query, 'prescription_date', $filters);

        return $query
            ->when($filters->status, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters->like(), fn (Builder $q, string $like) => $q->where(
                fn (Builder $inner) => $this->matchingPatient($inner->where('prescription_number', 'ILIKE', $like), 'patient', $like),
            ));
    }

    public function order(Builder $query): Builder
    {
        return $query->orderByDesc('prescription_date')->orderByDesc('id');
    }

    public function photoPath(): ?string
    {
        return 'patient.photograph';
    }

    public function sortable(): array
    {
        return ['date' => 'prescription_date', 'number' => 'prescription_number', 'valid_until' => 'valid_until', 'status' => 'status'];
    }

    public function doctorColumn(): ?string
    {
        return 'doctor_id';
    }

    /** @param  Prescription  $record */
    public function subtitles(Model $record): array
    {
        return array_filter(['patient' => $this->patientLine($record->patient)]);
    }

    /** @param  Prescription  $record */
    public function link(Model $record): ?string
    {
        return $record->customer_id ? "/patient-records?patient={$record->customer_id}&tab=prescriptions" : null;
    }

    /** @param  Prescription  $record */
    public function row(Model $record): array
    {
        $medicines = $record->items->map(function (PrescriptionItem $item) {
            $dose = collect([
                $item->frequency,
                $item->duration ? trim($item->duration.' '.$item->duration_unit) : null,
            ])->filter()->implode(', ');

            $name = trim($item->medicine_name_snapshot.' '.$item->strength_snapshot);

            return $dose !== '' ? "{$name} ({$dose})" : $name;
        });

        return [
            'date' => $this->date($record->prescription_date),
            'number' => $record->prescription_number,
            ...$this->patientCells($record->patient),
            'doctor' => $record->doctor?->name,
            'branch' => $record->location?->name,
            'count' => $medicines->count(),
            'medicines' => $medicines->implode('; '),
            'valid_until' => $this->date($record->valid_until),
            'status' => $this->words($record->status),
        ];
    }
}
