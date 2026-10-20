<?php

namespace App\Services\Exports\Datasets\Billing;

use App\Models\Tenant\InvoicePayment;
use App\Services\Exports\Column;
use App\Services\Exports\Dataset;
use App\Services\Exports\Datasets\Concerns\ReadsPatients;
use App\Services\Exports\ExportFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Money received against invoices — the receipts a till reconciles from.
 * Refunds are their own export (Refunds), so this one adds up to takings.
 */
class Payments extends Dataset
{
    use ReadsPatients;

    /** Ways of paying, as a receipt names them. */
    public const METHODS = [
        'cash' => 'Cash',
        'card' => 'Card',
        'upi' => 'UPI',
        'bank_transfer' => 'Bank transfer',
        'online' => 'Online',
        'cheque' => 'Cheque',
        'other' => 'Other',
    ];

    public function key(): string
    {
        return 'billing.payments';
    }

    public function tab(): string
    {
        return 'billing';
    }

    public function label(): string
    {
        return 'Payments';
    }

    public function description(): string
    {
        return 'Every payment received — receipt, invoice, method and amount.';
    }

    public function noun(): string
    {
        return 'payments';
    }

    public function icon(): string
    {
        return 'ti ti-cash';
    }

    public function dateLabel(): ?string
    {
        return 'Paid on';
    }

    /** The status filter here is the method — a payment has no other state. */
    public function statuses(): array
    {
        return self::METHODS;
    }

    public function statusLabel(): string
    {
        return 'Method';
    }

    public function searchHint(): ?string
    {
        return 'Receipt no., invoice no. or patient';
    }

    public function columns(): array
    {
        return [
            new Column('date', 'Date', Column::DATE, 9),
            new Column('receipt', 'Receipt no.', width: 10),
            new Column('invoice', 'Invoice no.', width: 10),
            new Column('patient', 'Patient', width: 12),
            new Column('uhid', 'UHID', width: 6.5),
            new Column('mobile', 'Mobile', width: 7.5),
            new Column('branch', 'Branch', width: 10),
            new Column('method', 'Method', width: 7),
            new Column('reference', 'Reference', width: 9),
            new Column('amount', 'Amount', Column::MONEY, 7.5),
            new Column('notes', 'Notes', width: 11),
        ];
    }

    public function query(ExportFilters $filters): Builder
    {
        $query = InvoicePayment::query()
            ->where('is_refund', false)
            ->with([
                'invoice:id,invoice_number,location_id,customer_id,walk_in_name,walk_in_phone',
                'invoice.customer:'.$this->patientFields(),
                'invoice.location:id,name',
            ]);

        if ($filters->branches !== null) {
            $query->whereHas('invoice', fn (Builder $invoice) => $invoice->whereIn('location_id', $filters->branches));
        }

        $this->between($query, 'paid_at', $filters);

        return $query
            ->when($filters->status, fn (Builder $q, string $method) => $q->where('method', $method))
            ->when($filters->like(), fn (Builder $q, string $like) => $q->where(fn (Builder $inner) => $inner
                ->where('receipt_number', 'ILIKE', $like)
                ->orWhere('reference', 'ILIKE', $like)
                ->orWhereHas('invoice', fn (Builder $invoice) => $this->matchingPatient(
                    $invoice->where('invoice_number', 'ILIKE', $like)->orWhere('walk_in_name', 'ILIKE', $like),
                    'customer',
                    $like,
                ))));
    }

    public function order(Builder $query): Builder
    {
        return $query->orderByDesc('paid_at')->orderByDesc('id');
    }

    public function photoPath(): ?string
    {
        return 'invoice.customer.photograph';
    }

    public function sortable(): array
    {
        return ['date' => 'paid_at', 'receipt' => 'receipt_number', 'method' => 'method', 'amount' => 'amount'];
    }

    /** @param  InvoicePayment  $record */
    public function subtitles(Model $record): array
    {
        return array_filter(['patient' => $this->patientLine($record->invoice?->customer)]);
    }

    /** @param  InvoicePayment  $record */
    public function link(Model $record): ?string
    {
        return '/billing/invoices/'.$record->invoice_id;
    }

    /** @param  InvoicePayment  $record */
    public function row(Model $record): array
    {
        $invoice = $record->invoice;

        return [
            'date' => $this->dateTime($record->paid_at),
            'receipt' => $record->receipt_number,
            'invoice' => $invoice?->invoice_number,
            ...$this->patientCells($invoice?->customer, $invoice?->walk_in_name, $invoice?->walk_in_phone),
            'branch' => $invoice?->location?->name,
            'method' => self::METHODS[$record->method] ?? $this->words($record->method),
            'reference' => $record->reference,
            'amount' => $this->money($record->amount),
            'notes' => $record->notes,
        ];
    }
}
