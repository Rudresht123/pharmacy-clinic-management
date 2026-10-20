<?php

namespace App\Services\Exports\Datasets\Patients;

use App\Models\Tenant\LabOrder;
use App\Services\Exports\Column;
use App\Services\Exports\Dataset;
use App\Services\Exports\Datasets\Concerns\ReadsPatients;
use App\Services\Exports\ExportFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Lab orders raised at a visit — the tests, what they cost, and how far they got. */
class LabOrders extends Dataset
{
    use ReadsPatients;

    public function key(): string
    {
        return 'patients.lab';
    }

    public function tab(): string
    {
        return 'patients';
    }

    public function label(): string
    {
        return 'Lab orders';
    }

    public function description(): string
    {
        return 'Tests ordered for each patient, their price, and whether the report is ready.';
    }

    public function noun(): string
    {
        return 'lab orders';
    }

    public function icon(): string
    {
        return 'ti ti-flask';
    }

    public function module(): ?string
    {
        return 'laboratory';
    }

    public function dateLabel(): ?string
    {
        return 'Ordered on';
    }

    public function statuses(): array
    {
        return collect(LabOrder::STATUSES)->mapWithKeys(fn (string $status) => [$status => $this->words($status)])->all();
    }

    public function searchHint(): ?string
    {
        return 'Order no., patient name, UHID or mobile';
    }

    public function columns(): array
    {
        return [
            new Column('date', 'Date', Column::DATE, 7),
            new Column('number', 'Order no.', width: 7.5),
            new Column('patient', 'Patient', width: 11),
            new Column('uhid', 'UHID', width: 6.5),
            new Column('mobile', 'Mobile', width: 7.5),
            new Column('doctor', 'Doctor', width: 10),
            new Column('branch', 'Branch', width: 10),
            new Column('count', 'Tests', Column::NUMBER, 4.5),
            new Column('tests', 'Tests ordered', width: 20),
            new Column('amount', 'Amount', Column::MONEY, 7),
            new Column('status', 'Status', width: 7),
            new Column('completed_at', 'Reported on', Column::DATE, 7.5),
        ];
    }

    public function query(ExportFilters $filters): Builder
    {
        $query = LabOrder::query()->with([
            'patient:'.$this->patientFields(),
            'doctor:id,name',
            'location:id,name',
            'items',
        ]);

        $this->atBranches($query, 'location_id', $filters);
        $this->between($query, 'order_date', $filters);

        return $query
            ->when($filters->status, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters->like(), fn (Builder $q, string $like) => $q->where(
                fn (Builder $inner) => $this->matchingPatient($inner->where('order_number', 'ILIKE', $like), 'patient', $like),
            ));
    }

    public function order(Builder $query): Builder
    {
        return $query->orderByDesc('order_date')->orderByDesc('id');
    }

    public function photoPath(): ?string
    {
        return 'patient.photograph';
    }

    public function sortable(): array
    {
        return ['date' => 'order_date', 'number' => 'order_number', 'status' => 'status', 'completed_at' => 'completed_at'];
    }

    public function doctorColumn(): ?string
    {
        return 'doctor_id';
    }

    /** @param  LabOrder  $record */
    public function subtitles(Model $record): array
    {
        return array_filter(['patient' => $this->patientLine($record->patient)]);
    }

    /** @param  LabOrder  $record */
    public function link(Model $record): ?string
    {
        return $record->customer_id ? "/patient-records?patient={$record->customer_id}&tab=laboratory" : null;
    }

    /** @param  LabOrder  $record */
    public function row(Model $record): array
    {
        return [
            'date' => $this->date($record->order_date),
            'number' => $record->order_number,
            ...$this->patientCells($record->patient),
            'doctor' => $record->doctor?->name,
            'branch' => $record->location?->name,
            'count' => $record->items->count(),
            'tests' => $record->items->pluck('test_name')->filter()->implode(', '),
            'amount' => $this->money($record->items->sum('price_snapshot')),
            'status' => $this->words($record->status),
            'completed_at' => $this->date($record->completed_at),
        ];
    }
}
