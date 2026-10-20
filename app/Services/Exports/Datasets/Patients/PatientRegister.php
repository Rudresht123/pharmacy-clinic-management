<?php

namespace App\Services\Exports\Datasets\Patients;

use App\Models\Tenant\Appointment;
use App\Models\Tenant\Customer;
use App\Services\Exports\Column;
use App\Services\Exports\Dataset;
use App\Services\Exports\ExportFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The patient register — one row per patient, with when they were last seen.
 *
 * A branch's share is the patients registered there; the organization's is
 * everybody. The last visit is read at the same branches, so a branch never
 * learns from it that a patient of theirs was seen somewhere else.
 *
 * Patient Records' own Export button writes these same columns from its
 * on-screen list (see PatientRecordController::export), so a role's column
 * rules hold there too.
 */
class PatientRegister extends Dataset
{
    /** Visits that never happened are not a "last seen". */
    private const NOT_ARRIVED = [Appointment::STATUS_BOOKED, Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW];

    public function key(): string
    {
        return 'patients.register';
    }

    public function tab(): string
    {
        return 'patients';
    }

    public function label(): string
    {
        return 'Patient register';
    }

    public function description(): string
    {
        return 'Every patient — contact details, where they registered and when they were last seen.';
    }

    public function noun(): string
    {
        return 'patients';
    }

    public function icon(): string
    {
        return 'ti ti-users';
    }

    public function dateLabel(): ?string
    {
        return 'Registered on';
    }

    public function defaultPeriod(): string
    {
        return 'all';
    }

    public function statuses(): array
    {
        return ['active' => 'Active', 'inactive' => 'Inactive'];
    }

    public function searchHint(): ?string
    {
        return 'Name, UHID, mobile or email';
    }

    public function columns(): array
    {
        return [
            new Column('code', 'UHID', width: 6.5),
            new Column('name', 'Name', width: 13),
            new Column('gender', 'Gender', width: 5),
            new Column('age', 'Age', Column::NUMBER, 3.5),
            new Column('date_of_birth', 'Date of birth', Column::DATE, 7.5),
            new Column('phone', 'Mobile', width: 7.5),
            new Column('email', 'Email', width: 10.5),
            new Column('address', 'Address', width: 16),
            new Column('branch', 'Registered at', width: 12.5),
            new Column('registered_on', 'Registered on', Column::DATE, 7.5),
            new Column('last_visit', 'Last visit', Column::DATE, 7.5),
            new Column('status', 'Status', width: 4.5),
        ];
    }

    public function query(ExportFilters $filters): Builder
    {
        $query = Customer::query()
            ->with('registeredLocation:id,name')
            ->select('customers.*')
            ->addSelect(['last_visit_on' => $this->lastVisit($filters->branches)]);

        $this->atBranches($query, 'registered_location_id', $filters);
        $this->between($query, 'customers.created_at', $filters);

        return $query
            ->when($filters->status === 'active', fn (Builder $q) => $q->where('is_active', true))
            ->when($filters->status === 'inactive', fn (Builder $q) => $q->where('is_active', false))
            ->when($filters->like(), fn (Builder $q, string $like) => $q->where(fn (Builder $inner) => $inner
                ->where('name', 'ILIKE', $like)
                ->orWhere('code', 'ILIKE', $like)
                ->orWhere('phone', 'ILIKE', $like)
                ->orWhere('email', 'ILIKE', $like)));
    }

    public function order(Builder $query): Builder
    {
        return $query->orderBy('name')->orderBy('id');
    }

    /** @param  Customer  $record */
    public function muted(Model $record): bool
    {
        return ! $record->is_active;
    }

    public function photoPath(): ?string
    {
        return 'photograph';
    }

    public function sortable(): array
    {
        return [
            'code' => 'code',
            'name' => 'name',
            'age' => 'date_of_birth',
            'registered_on' => 'customers.created_at',
            'last_visit' => 'last_visit_on',
            'status' => 'is_active',
        ];
    }

    /** @param  Customer  $record */
    public function subtitles(Model $record): array
    {
        $line = collect([$record->gender ? ucfirst($record->gender) : null, $record->age() !== null ? $record->age().' yrs' : null])
            ->filter()
            ->implode(', ');

        return $line !== '' ? ['name' => $line] : [];
    }

    /** @param  Customer  $record */
    public function link(Model $record): ?string
    {
        return '/patient-records?patient='.$record->id;
    }

    /** @param  Customer  $record */
    public function row(Model $record): array
    {
        $address = collect([$record->address, $record->city, $record->district, $record->state, $record->pincode])
            ->map(fn ($part) => trim((string) $part))
            ->filter()
            ->unique()
            ->implode(', ');

        return [
            'code' => $record->code,
            'name' => $record->name,
            'gender' => $this->words($record->gender),
            'age' => $record->age(),
            'date_of_birth' => $this->date($record->date_of_birth),
            'phone' => $record->phone,
            'email' => $record->email,
            'address' => $address !== '' ? $address : null,
            'branch' => $record->registeredLocation?->name,
            'registered_on' => $this->date($record->created_at),
            'last_visit' => $this->date($record->getAttribute('last_visit_on')),
            'status' => $record->is_active ? 'Active' : 'Inactive',
        ];
    }

    /**
     * The newest visit that actually happened, at these branches.
     *
     * @param  list<int>|null  $branches
     */
    public function lastVisit(?array $branches): Builder
    {
        return Appointment::query()
            ->select('appointment_date')
            ->whereColumn('appointments.customer_id', 'customers.id')
            ->whereNotIn('status', self::NOT_ARRIVED)
            ->when($branches !== null, fn (Builder $q) => $q->whereIn('location_id', $branches))
            ->orderByDesc('appointment_date')
            ->limit(1);
    }
}
