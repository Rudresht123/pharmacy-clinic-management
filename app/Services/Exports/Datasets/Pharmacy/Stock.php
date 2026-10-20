<?php

namespace App\Services\Exports\Datasets\Pharmacy;

use App\Models\Tenant\MedicineBatch;
use App\Models\Tenant\PharmacyStore;
use App\Services\Exports\Column;
use App\Services\Exports\Dataset;
use App\Services\Exports\ExportFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * What is on the shelf now — one row per batch, with its value at cost.
 *
 * A snapshot, so no date range: the stock of last March is the movements
 * export's question. Batches with nothing left are left out unless asked
 * for, because a register of empties is not a stock list.
 */
class Stock extends Dataset
{
    public function key(): string
    {
        return 'pharmacy.stock';
    }

    public function tab(): string
    {
        return 'pharmacy';
    }

    public function label(): string
    {
        return 'Stock';
    }

    public function description(): string
    {
        return 'Every batch on the shelf today — quantity, expiry, prices and value at cost.';
    }

    public function noun(): string
    {
        return 'batches';
    }

    public function icon(): string
    {
        return 'ti ti-packages';
    }

    public function defaultPeriod(): string
    {
        return 'all';
    }

    public function statuses(): array
    {
        return [
            'in_stock' => 'In stock',
            'expired' => 'Expired, still on the shelf',
            'blocked' => 'Blocked',
            'empty' => 'Sold out',
        ];
    }

    public function searchHint(): ?string
    {
        return 'Medicine or batch no.';
    }

    public function columns(): array
    {
        return [
            new Column('store', 'Store', width: 10),
            new Column('medicine', 'Medicine', width: 18),
            new Column('generic', 'Generic name', width: 12),
            new Column('batch', 'Batch', width: 7.5),
            new Column('expiry', 'Expiry', Column::DATE, 7),
            new Column('quantity', 'Quantity', Column::NUMBER, 6),
            new Column('mrp', 'MRP', Column::MONEY, 6),
            new Column('purchase', 'Purchase price', Column::MONEY, 7),
            new Column('selling', 'Selling price', Column::MONEY, 7),
            new Column('value', 'Value at cost', Column::MONEY, 7.5),
            new Column('supplier', 'Supplier', width: 10),
            new Column('status', 'Status', width: 6),
        ];
    }

    public function query(ExportFilters $filters): Builder
    {
        $today = Carbon::today();

        $query = MedicineBatch::query()->with([
            'medicine:id,generic_name,brand_name,strength,dosage_form',
            'store:id,name',
            'supplier:id,name',
        ]);

        if ($filters->branches !== null) {
            $query->whereIn('pharmacy_store_id', PharmacyStore::query()->whereIn('location_id', $filters->branches)->select('id'));
        }

        match ($filters->status) {
            'empty' => $query->where('quantity_available', '<=', 0),
            'expired' => $query->where('quantity_available', '>', 0)->whereDate('expiry_date', '<', $today),
            'blocked' => $query->where('quantity_available', '>', 0)->whereNotNull('blocked_at'),
            'in_stock' => $query->where('quantity_available', '>', 0)->whereNull('blocked_at')->whereDate('expiry_date', '>=', $today),
            default => $query->where('quantity_available', '>', 0),
        };

        return $query->when($filters->like(), fn (Builder $q, string $like) => $q->where(fn (Builder $inner) => $inner
            ->where('batch_number', 'ILIKE', $like)
            ->orWhereHas('medicine', fn (Builder $medicine) => $medicine
                ->where('brand_name', 'ILIKE', $like)
                ->orWhere('generic_name', 'ILIKE', $like))));
    }

    public function order(Builder $query): Builder
    {
        return $query->orderBy('pharmacy_store_id')->orderBy('medicine_id')->orderBy('expiry_date')->orderBy('id');
    }

    public function sortable(): array
    {
        return ['batch' => 'batch_number', 'expiry' => 'expiry_date', 'quantity' => 'quantity_available', 'mrp' => 'mrp', 'purchase' => 'purchase_price'];
    }

    /** @param  MedicineBatch  $record */
    public function row(Model $record): array
    {
        $quantity = (int) $record->quantity_available;
        $expired = $record->expiry_date !== null && $record->expiry_date->lt(Carbon::today());

        return [
            'store' => $record->store?->name,
            'medicine' => $record->medicine?->displayName(),
            'generic' => $record->medicine?->generic_name,
            'batch' => $record->batch_number,
            'expiry' => $this->date($record->expiry_date),
            'quantity' => $quantity,
            'mrp' => $this->money($record->mrp),
            'purchase' => $this->money($record->purchase_price),
            'selling' => $this->money($record->selling_price),
            'value' => $this->money($quantity * (float) $record->purchase_price),
            'supplier' => $record->supplier?->name,
            'status' => match (true) {
                $quantity <= 0 => 'Sold out',
                $record->blocked_at !== null => 'Blocked',
                $expired => 'Expired',
                default => 'In stock',
            },
        ];
    }
}
