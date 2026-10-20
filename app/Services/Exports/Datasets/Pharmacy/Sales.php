<?php

namespace App\Services\Exports\Datasets\Pharmacy;

use App\Models\Tenant\PharmacySale;
use App\Services\Exports\Column;
use App\Services\Exports\Dataset;
use App\Services\Exports\Datasets\Concerns\ReadsPatients;
use App\Services\Exports\ExportFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** The pharmacy's bills — counter sales and dispensed prescriptions alike. */
class Sales extends Dataset
{
    use ReadsPatients;

    public function key(): string
    {
        return 'pharmacy.sales';
    }

    public function tab(): string
    {
        return 'pharmacy';
    }

    public function label(): string
    {
        return 'Bills';
    }

    public function description(): string
    {
        return 'Every pharmacy bill — what it came to, what was paid and how.';
    }

    public function noun(): string
    {
        return 'bills';
    }

    public function icon(): string
    {
        return 'ti ti-receipt';
    }

    public function dateLabel(): ?string
    {
        return 'Bill date';
    }

    public function statuses(): array
    {
        return [
            'completed' => 'Completed',
            'paid' => 'Paid',
            'partial' => 'Part paid',
            'unpaid' => 'Unpaid',
            'cancelled' => 'Cancelled',
        ];
    }

    public function searchHint(): ?string
    {
        return 'Bill no., patient name, UHID or mobile';
    }

    public function columns(): array
    {
        return [
            new Column('date', 'Date', Column::DATE, 9),
            new Column('number', 'Bill no.', width: 7.5),
            new Column('patient', 'Patient', width: 11),
            new Column('uhid', 'UHID', width: 6),
            new Column('mobile', 'Mobile', width: 7),
            new Column('doctor', 'Doctor', width: 9),
            new Column('store', 'Store', width: 9),
            new Column('items', 'Items', Column::NUMBER, 4),
            new Column('subtotal', 'Subtotal', Column::MONEY, 6.5),
            new Column('discount', 'Discount', Column::MONEY, 6),
            new Column('tax', 'Tax', Column::MONEY, 5.5),
            new Column('total', 'Total', Column::MONEY, 6.5),
            new Column('paid', 'Paid', Column::MONEY, 6.5),
            new Column('returned', 'Returned', Column::MONEY, 6),
            new Column('payment', 'Payment', width: 6),
            new Column('methods', 'Paid by', width: 6.5),
            new Column('status', 'Status', width: 6.5),
            new Column('billed_by', 'Billed by', width: 8),
        ];
    }

    public function query(ExportFilters $filters): Builder
    {
        $query = PharmacySale::query()
            ->with([
                'customer:'.$this->patientFields(),
                'doctor:id,name',
                'store:id,name',
                'payments',
            ])
            ->withCount('items');

        $this->atBranches($query, 'location_id', $filters);
        $this->between($query, 'sale_date', $filters);

        return $query
            ->when($filters->status, function (Builder $q, string $status) {
                match ($status) {
                    'completed', 'cancelled' => $q->where('status', $status),
                    default => $q->where('status', PharmacySale::COMPLETED)->where('payment_status', $status),
                };
            })
            ->when($filters->like(), fn (Builder $q, string $like) => $q->where(fn (Builder $inner) => $this->matchingPatient(
                $inner->where('sale_number', 'ILIKE', $like)->orWhere('walk_in_name', 'ILIKE', $like)->orWhere('walk_in_phone', 'ILIKE', $like),
                'customer',
                $like,
            )));
    }

    public function order(Builder $query): Builder
    {
        return $query->orderByDesc('sale_date')->orderByDesc('id');
    }

    public function photoPath(): ?string
    {
        return 'customer.photograph';
    }

    public function sortable(): array
    {
        return ['date' => 'sale_date', 'number' => 'sale_number', 'total' => 'total_amount', 'paid' => 'paid_amount', 'status' => 'status'];
    }

    public function doctorColumn(): ?string
    {
        return 'doctor_id';
    }

    /** @param  PharmacySale  $record */
    public function subtitles(Model $record): array
    {
        return array_filter(['patient' => $this->patientLine($record->customer)]);
    }

    /** @param  PharmacySale  $record */
    public function link(Model $record): ?string
    {
        return $record->customer_id ? "/patient-records?patient={$record->customer_id}&tab=pharmacy" : null;
    }

    /** @param  PharmacySale  $record */
    public function row(Model $record): array
    {
        return [
            'date' => $this->dateTime($record->sale_date),
            'number' => $record->sale_number,
            ...$this->patientCells($record->customer, $record->walk_in_name, $record->walk_in_phone),
            'doctor' => $record->doctor?->name,
            'store' => $record->store?->name,
            'items' => (int) $record->items_count,
            'subtotal' => $this->money($record->subtotal),
            'discount' => $this->money($record->discount_amount),
            'tax' => $this->money($record->tax_amount),
            'total' => $this->money($record->total_amount),
            'paid' => $this->money($record->paid_amount),
            'returned' => $this->money($record->returned_amount),
            'payment' => $record->payment_status === PharmacySale::BILLED_VIA_INVOICE ? 'On the visit bill' : $this->words($record->payment_status),
            'methods' => $record->payments->where('is_refund', false)->pluck('method')->unique()
                ->map(fn ($method) => $this->words($method))->implode(', ') ?: null,
            'status' => $this->words($record->status),
            'billed_by' => $record->created_by_name,
        ];
    }
}
