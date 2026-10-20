<?php

namespace App\Services\Exports\Datasets\Pharmacy;

use App\Models\Tenant\PharmacySaleReturn;
use App\Models\Tenant\PharmacyStore;
use App\Services\Exports\Column;
use App\Services\Exports\Dataset;
use App\Services\Exports\Datasets\Concerns\ReadsPatients;
use App\Services\Exports\ExportFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Goods taken back at the counter, and what was refunded for them. */
class Returns extends Dataset
{
    use ReadsPatients;

    /** @var array<int, string>|null store names by id, read once */
    private ?array $stores = null;

    public function key(): string
    {
        return 'pharmacy.returns';
    }

    public function tab(): string
    {
        return 'pharmacy';
    }

    public function label(): string
    {
        return 'Returns';
    }

    public function description(): string
    {
        return 'Medicines brought back against a bill, and the refund given.';
    }

    public function noun(): string
    {
        return 'returns';
    }

    public function icon(): string
    {
        return 'ti ti-arrow-back-up';
    }

    public function dateLabel(): ?string
    {
        return 'Return date';
    }

    public function searchHint(): ?string
    {
        return 'Return no., bill no. or patient';
    }

    public function columns(): array
    {
        return [
            new Column('date', 'Date', Column::DATE, 9),
            new Column('number', 'Return no.', width: 9),
            new Column('bill', 'Against bill', width: 8),
            new Column('patient', 'Patient', width: 12),
            new Column('uhid', 'UHID', width: 6.5),
            new Column('mobile', 'Mobile', width: 7.5),
            new Column('store', 'Store', width: 10),
            new Column('items', 'Items', Column::NUMBER, 4.5),
            new Column('amount', 'Value', Column::MONEY, 7),
            new Column('refund', 'Refunded', Column::MONEY, 7),
            new Column('reason', 'Reason', width: 13),
            new Column('by', 'Taken by', width: 8),
        ];
    }

    public function query(ExportFilters $filters): Builder
    {
        $query = PharmacySaleReturn::query()
            ->with(['sale:id,sale_number,customer_id,walk_in_name,walk_in_phone', 'sale.customer:'.$this->patientFields()])
            ->withCount('items');

        $this->atBranches($query, 'location_id', $filters);
        $this->between($query, 'return_date', $filters);

        return $query->when($filters->like(), fn (Builder $q, string $like) => $q->where(fn (Builder $inner) => $inner
            ->where('return_number', 'ILIKE', $like)
            ->orWhereHas('sale', fn (Builder $sale) => $this->matchingPatient(
                $sale->where('sale_number', 'ILIKE', $like)->orWhere('walk_in_name', 'ILIKE', $like),
                'customer',
                $like,
            ))));
    }

    public function order(Builder $query): Builder
    {
        return $query->orderByDesc('return_date')->orderByDesc('id');
    }

    public function photoPath(): ?string
    {
        return 'sale.customer.photograph';
    }

    public function sortable(): array
    {
        return ['date' => 'return_date', 'number' => 'return_number', 'amount' => 'total_amount', 'refund' => 'refund_amount'];
    }

    /** @param  PharmacySaleReturn  $record */
    public function row(Model $record): array
    {
        // A store removed since is still named on the returns it took.
        $this->stores ??= PharmacyStore::query()->withoutGlobalScopes()->pluck('name', 'id')->all();

        $sale = $record->sale;

        return [
            'date' => $this->dateTime($record->return_date),
            'number' => $record->return_number,
            'bill' => $sale?->sale_number,
            ...$this->patientCells($sale?->customer, $sale?->walk_in_name, $sale?->walk_in_phone),
            'store' => $this->stores[$record->pharmacy_store_id] ?? null,
            'items' => (int) $record->items_count,
            'amount' => $this->money($record->total_amount),
            'refund' => $this->money($record->refund_amount),
            'reason' => $record->reason,
            'by' => $record->created_by_name,
        ];
    }
}
