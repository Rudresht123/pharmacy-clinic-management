<?php

namespace App\Services\Exports\Datasets\Pharmacy;

use App\Models\Tenant\StockInward;
use App\Services\Exports\Column;
use App\Services\Exports\Dataset;
use App\Services\Exports\ExportFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Goods received — every GRN, against which supplier's invoice, for how much. */
class Purchases extends Dataset
{
    public function key(): string
    {
        return 'pharmacy.purchases';
    }

    public function tab(): string
    {
        return 'pharmacy';
    }

    public function label(): string
    {
        return 'Purchases (GRN)';
    }

    public function description(): string
    {
        return 'Stock received from suppliers — invoice numbers, amounts and who received it.';
    }

    public function noun(): string
    {
        return 'receipts';
    }

    public function icon(): string
    {
        return 'ti ti-truck-delivery';
    }

    public function dateLabel(): ?string
    {
        return 'Received on';
    }

    public function statuses(): array
    {
        return [StockInward::POSTED => 'Posted', StockInward::CANCELLED => 'Cancelled'];
    }

    public function searchHint(): ?string
    {
        return 'GRN no., supplier or supplier invoice';
    }

    public function columns(): array
    {
        return [
            new Column('date', 'Received', Column::DATE, 7),
            new Column('number', 'GRN no.', width: 7.5),
            new Column('type', 'Type', width: 8),
            new Column('supplier', 'Supplier', width: 14),
            new Column('invoice', 'Supplier invoice', width: 9),
            new Column('invoice_date', 'Invoice date', Column::DATE, 7),
            new Column('store', 'Store', width: 10),
            new Column('items', 'Items', Column::NUMBER, 4.5),
            new Column('amount', 'Amount', Column::MONEY, 8),
            new Column('status', 'Status', width: 6),
            new Column('by', 'Received by', width: 9),
        ];
    }

    public function query(ExportFilters $filters): Builder
    {
        $query = StockInward::query()
            ->with(['supplier:id,name', 'store:id,name'])
            ->withCount('items');

        $this->atBranches($query, 'location_id', $filters);
        $this->between($query, 'received_date', $filters);

        return $query
            ->when($filters->status, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters->like(), fn (Builder $q, string $like) => $q->where(fn (Builder $inner) => $inner
                ->where('inward_number', 'ILIKE', $like)
                ->orWhere('supplier_invoice_no', 'ILIKE', $like)
                ->orWhereHas('supplier', fn (Builder $supplier) => $supplier->where('name', 'ILIKE', $like))));
    }

    public function order(Builder $query): Builder
    {
        return $query->orderByDesc('received_date')->orderByDesc('id');
    }

    public function sortable(): array
    {
        return ['date' => 'received_date', 'number' => 'inward_number', 'amount' => 'total_amount', 'status' => 'status'];
    }

    /** @param  StockInward  $record */
    public function row(Model $record): array
    {
        return [
            'date' => $this->date($record->received_date),
            'number' => $record->inward_number,
            'type' => $this->words($record->inward_type),
            'supplier' => $record->supplier?->name,
            'invoice' => $record->supplier_invoice_no,
            'invoice_date' => $this->date($record->supplier_invoice_date),
            'store' => $record->store?->name,
            'items' => (int) $record->items_count,
            'amount' => $this->money($record->total_amount),
            'status' => $this->words($record->status),
            'by' => $record->created_by_name,
        ];
    }
}
