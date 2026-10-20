<?php

namespace App\Services\Exports\Datasets\Billing;

use App\Models\Tenant\InvoicePayment;
use App\Services\Exports\Column;
use App\Services\Exports\Dataset;
use App\Services\Exports\Datasets\Concerns\ReadsPatients;
use App\Services\Exports\ExportFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Money given back — each refund, the payment it reversed, and why. */
class Refunds extends Dataset
{
    use ReadsPatients;

    public function key(): string
    {
        return 'billing.refunds';
    }

    public function tab(): string
    {
        return 'billing';
    }

    public function label(): string
    {
        return 'Refunds';
    }

    public function description(): string
    {
        return 'Every refund — against which receipt and invoice, how much, and the reason.';
    }

    public function noun(): string
    {
        return 'refunds';
    }

    public function icon(): string
    {
        return 'ti ti-receipt-refund';
    }

    public function dateLabel(): ?string
    {
        return 'Refunded on';
    }

    public function statuses(): array
    {
        return Payments::METHODS;
    }

    public function statusLabel(): string
    {
        return 'Method';
    }

    public function searchHint(): ?string
    {
        return 'Refund no., invoice no. or patient';
    }

    public function columns(): array
    {
        return [
            new Column('date', 'Date', Column::DATE, 9),
            new Column('number', 'Refund no.', width: 10),
            new Column('against', 'Against receipt', width: 10),
            new Column('invoice', 'Invoice no.', width: 10),
            new Column('patient', 'Patient', width: 12),
            new Column('mobile', 'Mobile', width: 7.5),
            new Column('branch', 'Branch', width: 10),
            new Column('method', 'Method', width: 7),
            new Column('amount', 'Amount', Column::MONEY, 7.5),
            new Column('reason', 'Reason', width: 13),
        ];
    }

    public function query(ExportFilters $filters): Builder
    {
        $query = InvoicePayment::query()
            ->where('is_refund', true)
            ->with([
                'refunded:id,receipt_number',
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
        return ['date' => 'paid_at', 'number' => 'receipt_number', 'method' => 'method', 'amount' => 'amount'];
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
        $patient = $this->patientCells($invoice?->customer, $invoice?->walk_in_name, $invoice?->walk_in_phone);

        return [
            'date' => $this->dateTime($record->paid_at),
            'number' => $record->receipt_number,
            'against' => $record->refunded?->receipt_number,
            'invoice' => $invoice?->invoice_number,
            'patient' => $patient['patient'],
            'mobile' => $patient['mobile'],
            'branch' => $invoice?->location?->name,
            'method' => Payments::METHODS[$record->method] ?? $this->words($record->method),
            'amount' => $this->money(abs((float) $record->amount)),
            'reason' => $record->notes,
        ];
    }
}
