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
 * Batches still on the shelf that expire inside the dates chosen — the list
 * a pharmacist works from to return stock before it is worthless. Soonest
 * first, and the dates look forward rather than back (see `period`).
 */
class Expiry extends Dataset
{
    public function key(): string
    {
        return 'pharmacy.expiry';
    }

    public function tab(): string
    {
        return 'pharmacy';
    }

    public function label(): string
    {
        return 'Expiring stock';
    }

    public function description(): string
    {
        return 'Batches on the shelf that expire in the dates you pick, soonest first.';
    }

    public function noun(): string
    {
        return 'batches';
    }

    public function icon(): string
    {
        return 'ti ti-calendar-exclamation';
    }

    public function dateLabel(): ?string
    {
        return 'Expires';
    }

    /** Forward-looking: the next ninety days, unless somebody picks otherwise. */
    public function defaultPeriod(): string
    {
        return 'next_90';
    }

    public function searchHint(): ?string
    {
        return 'Medicine or batch no.';
    }

    public function columns(): array
    {
        return [
            new Column('store', 'Store', width: 11),
            new Column('medicine', 'Medicine', width: 20),
            new Column('batch', 'Batch', width: 8),
            new Column('expiry', 'Expiry', Column::DATE, 7.5),
            new Column('days_left', 'Days left', Column::NUMBER, 6),
            new Column('quantity', 'Quantity', Column::NUMBER, 6),
            new Column('purchase', 'Purchase price', Column::MONEY, 7.5),
            new Column('value', 'Value at cost', Column::MONEY, 8),
            new Column('supplier', 'Supplier', width: 12),
        ];
    }

    public function query(ExportFilters $filters): Builder
    {
        $query = MedicineBatch::query()
            ->with(['medicine:id,generic_name,brand_name,strength,dosage_form', 'store:id,name', 'supplier:id,name'])
            ->where('quantity_available', '>', 0)
            ->whereNotNull('expiry_date');

        if ($filters->branches !== null) {
            $query->whereIn('pharmacy_store_id', PharmacyStore::query()->whereIn('location_id', $filters->branches)->select('id'));
        }

        $this->between($query, 'expiry_date', $filters);

        return $query->when($filters->like(), fn (Builder $q, string $like) => $q->where(fn (Builder $inner) => $inner
            ->where('batch_number', 'ILIKE', $like)
            ->orWhereHas('medicine', fn (Builder $medicine) => $medicine
                ->where('brand_name', 'ILIKE', $like)
                ->orWhere('generic_name', 'ILIKE', $like))));
    }

    public function order(Builder $query): Builder
    {
        return $query->orderBy('expiry_date')->orderBy('id');
    }

    public function sortable(): array
    {
        return ['expiry' => 'expiry_date', 'days_left' => 'expiry_date', 'quantity' => 'quantity_available', 'batch' => 'batch_number'];
    }

    /** @param  MedicineBatch  $record */
    public function row(Model $record): array
    {
        $quantity = (int) $record->quantity_available;

        return [
            'store' => $record->store?->name,
            'medicine' => $record->medicine?->displayName(),
            'batch' => $record->batch_number,
            'expiry' => $this->date($record->expiry_date),
            'days_left' => (int) Carbon::today()->diffInDays($record->expiry_date, false),
            'quantity' => $quantity,
            'purchase' => $this->money($record->purchase_price),
            'value' => $this->money($quantity * (float) $record->purchase_price),
            'supplier' => $record->supplier?->name,
        ];
    }
}
