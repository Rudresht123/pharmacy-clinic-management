<?php

namespace App\Services\Exports\Datasets\Patients;

use App\Models\Tenant\Appointment;
use App\Services\Exports\Column;
use App\Services\Exports\Dataset;
use App\Services\Exports\Datasets\Concerns\ReadsPatients;
use App\Services\Exports\ExportFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** Appointments and OPD visits — booked, walked in, seen, missed. */
class Visits extends Dataset
{
    use ReadsPatients;

    public function key(): string
    {
        return 'patients.visits';
    }

    public function tab(): string
    {
        return 'patients';
    }

    public function label(): string
    {
        return 'Appointments & visits';
    }

    public function description(): string
    {
        return 'Every booking and walk-in — who, with which doctor, and how the visit ended.';
    }

    public function noun(): string
    {
        return 'visits';
    }

    public function icon(): string
    {
        return 'ti ti-calendar-event';
    }

    public function module(): ?string
    {
        return 'appointments';
    }

    public function dateLabel(): ?string
    {
        return 'Visit date';
    }

    public function statuses(): array
    {
        return collect(Appointment::STATUSES)->mapWithKeys(fn (string $status) => [$status => $this->words($status)])->all();
    }

    public function searchHint(): ?string
    {
        return 'Patient name, UHID or mobile';
    }

    public function columns(): array
    {
        return [
            new Column('date', 'Date', Column::DATE, 7),
            new Column('time', 'Time', width: 5.5),
            new Column('token', 'Token', Column::NUMBER, 4),
            new Column('patient', 'Patient', width: 12),
            new Column('uhid', 'UHID', width: 6.5),
            new Column('mobile', 'Mobile', width: 7.5),
            new Column('doctor', 'Doctor', width: 11),
            new Column('branch', 'Branch', width: 11),
            new Column('type', 'Type', width: 5.5),
            new Column('mode', 'Mode', width: 5.5),
            new Column('status', 'Status', width: 8.5),
            new Column('reason', 'Cancellation reason', width: 11),
        ];
    }

    public function query(ExportFilters $filters): Builder
    {
        $query = Appointment::query()->with([
            'customer:'.$this->patientFields(),
            'doctor:id,name',
            'location:id,name',
        ]);

        $this->atBranches($query, 'location_id', $filters);
        $this->between($query, 'appointment_date', $filters);

        return $query
            ->when($filters->status, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters->like(), fn (Builder $q, string $like) => $q->where(
                fn (Builder $inner) => $this->matchingPatient($inner, 'customer', $like),
            ));
    }

    public function order(Builder $query): Builder
    {
        return $query->orderByDesc('appointment_date')->orderBy('token_no')->orderByDesc('id');
    }

    public function photoPath(): ?string
    {
        return 'customer.photograph';
    }

    public function sortable(): array
    {
        return ['date' => 'appointment_date', 'token' => 'token_no', 'type' => 'type', 'status' => 'status'];
    }

    public function doctorColumn(): ?string
    {
        return 'doctor_id';
    }

    /** @param  Appointment  $record */
    public function subtitles(Model $record): array
    {
        return array_filter(['patient' => $this->patientLine($record->customer)]);
    }

    /** @param  Appointment  $record */
    public function link(Model $record): ?string
    {
        return $record->customer_id ? "/patient-records?patient={$record->customer_id}&tab=appointments" : null;
    }

    /** @param  Appointment  $record */
    public function row(Model $record): array
    {
        $time = $record->slot_at ? Carbon::parse($record->slot_at)->format('h:i A') : $record->checked_in_at?->format('h:i A');

        return [
            'date' => $this->date($record->appointment_date),
            'time' => $time,
            'token' => $record->token_no,
            ...$this->patientCells($record->customer),
            'doctor' => $record->doctor?->name,
            'branch' => $record->location?->name,
            'type' => $record->type === Appointment::WALK_IN ? 'Walk-in' : 'Booked',
            'mode' => $record->mode === Appointment::MODE_VIDEO ? 'Video' : 'In clinic',
            'status' => $this->words($record->status),
            'reason' => $record->cancellation_reason,
        ];
    }
}
