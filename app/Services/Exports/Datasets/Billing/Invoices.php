<?php

namespace App\Services\Exports\Datasets\Billing;

use App\Models\Tenant\Invoice;
use App\Services\Exports\Column;
use App\Services\Exports\Dataset;
use App\Services\Exports\Datasets\Concerns\ReadsPatients;
use App\Services\Exports\ExportFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Every invoice the OPD, the lab and the desk raised — what it came to and where it stands. */
class Invoices extends Dataset
{
    use ReadsPatients;

    /** What each kind of bill is called on paper. */
    public const KIND_LABELS = [
        Invoice::KIND_VISIT => 'OPD visit',
        Invoice::KIND_REGISTRATION => 'Registration',
        Invoice::KIND_MANUAL => 'Manual',
        Invoice::KIND_LAB => 'Laboratory',
    ];

    public function key(): string
    {
        return 'billing.invoices';
    }

    public function tab(): string
    {
        return 'billing';
    }

    public function label(): string
    {
        return 'Invoices';
    }

    public function description(): string
    {
        return 'Every bill raised — amounts, tax, what was paid and what is still owed.';
    }

    public function noun(): string
    {
        return 'invoices';
    }

    public function icon(): string
    {
        return 'ti ti-file-invoice';
    }

    public function dateLabel(): ?string
    {
        return 'Invoice date';
    }

    public function statuses(): array
    {
        return collect(Invoice::STATUSES)->mapWithKeys(fn (string $status) => [$status => $this->words($status)])->all();
    }

    public function searchHint(): ?string
    {
        return 'Invoice no., patient name, UHID or mobile';
    }

    public function columns(): array
    {
        return [
            new Column('date', 'Date', Column::DATE, 7),
            new Column('number', 'Invoice no.', width: 10),
            new Column('patient', 'Patient', width: 11),
            new Column('uhid', 'UHID', width: 6),
            new Column('mobile', 'Mobile', width: 7),
            new Column('kind', 'Department', width: 7),
            new Column('doctor', 'Doctor', width: 9),
            new Column('branch', 'Branch', width: 9),
            new Column('subtotal', 'Subtotal', Column::MONEY, 6.5),
            new Column('discount', 'Discount', Column::MONEY, 6),
            new Column('tax', 'Tax', Column::MONEY, 5.5),
            new Column('total', 'Total', Column::MONEY, 6.5),
            new Column('paid', 'Paid', Column::MONEY, 6.5),
            new Column('refunded', 'Refunded', Column::MONEY, 6),
            new Column('balance', 'Balance', Column::MONEY, 6.5),
            new Column('status', 'Status', width: 7),
            new Column('billed_by', 'Billed by', width: 8),
        ];
    }

    public function query(ExportFilters $filters): Builder
    {
        $query = Invoice::query()->with([
            'customer:'.$this->patientFields(),
            'doctor:id,name',
            'location:id,name',
        ]);

        $this->atBranches($query, 'location_id', $filters);
        $this->between($query, 'invoice_date', $filters);

        return $query
            ->when($filters->status, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters->like(), fn (Builder $q, string $like) => $q->where(fn (Builder $inner) => $this->matchingPatient(
                $inner->where('invoice_number', 'ILIKE', $like)->orWhere('walk_in_name', 'ILIKE', $like)->orWhere('walk_in_phone', 'ILIKE', $like),
                'customer',
                $like,
            )));
    }

    public function order(Builder $query): Builder
    {
        return $query->orderByDesc('invoice_date')->orderByDesc('id');
    }

    public function photoPath(): ?string
    {
        return 'customer.photograph';
    }

    public function sortable(): array
    {
        return ['date' => 'invoice_date', 'number' => 'invoice_number', 'total' => 'total_amount', 'paid' => 'paid_amount', 'status' => 'status'];
    }

    public function doctorColumn(): ?string
    {
        return 'doctor_id';
    }

    /** @param  Invoice  $record */
    public function subtitles(Model $record): array
    {
        return array_filter(['patient' => $this->patientLine($record->customer)]);
    }

    /** @param  Invoice  $record */
    public function link(Model $record): ?string
    {
        return '/billing/invoices/'.$record->id;
    }

    /** @param  Invoice  $record */
    public function row(Model $record): array
    {
        return [
            'date' => $this->date($record->invoice_date),
            'number' => $record->invoice_number,
            ...$this->patientCells($record->customer, $record->walk_in_name, $record->walk_in_phone),
            'kind' => self::KIND_LABELS[$record->kind] ?? $this->words($record->kind),
            'doctor' => $record->doctor?->name,
            'branch' => $record->location?->name,
            'subtotal' => $this->money($record->subtotal),
            'discount' => $this->money($record->discount_amount),
            'tax' => $this->money($record->tax_amount),
            'total' => $this->money($record->total_amount),
            'paid' => $this->money($record->paid_amount),
            'refunded' => $this->money($record->refunded_amount),
            'balance' => $this->money($record->outstanding()),
            'status' => $this->words($record->status),
            'billed_by' => $record->created_by_name,
        ];
    }
}
