<?php

namespace App\Services\Exports\Datasets\Pharmacy;

use App\Models\Tenant\StockMovement;
use App\Services\Exports\Column;
use App\Services\Exports\Dataset;
use App\Services\Exports\ExportFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** The stock ledger — every unit in and out, and the balance it left. */
class Movements extends Dataset
{
    public function key(): string
    {
        return 'pharmacy.movements';
    }

    public function tab(): string
    {
        return 'pharmacy';
    }

    public function label(): string
    {
        return 'Stock movements';
    }

    public function description(): string
    {
        return 'Every receipt, sale, dispensing, transfer and adjustment, with the balance after it.';
    }

    public function noun(): string
    {
        return 'movements';
    }

    public function icon(): string
    {
        return 'ti ti-arrows-exchange';
    }

    public function dateLabel(): ?string
    {
        return 'Moved on';
    }

    public function statuses(): array
    {
        return collect(StockMovement::TYPES)->mapWithKeys(fn (string $type) => [$type => $this->words($type)])->all();
    }

    public function statusLabel(): string
    {
        return 'Movement';
    }

    public function searchHint(): ?string
    {
        return 'Medicine or batch no.';
    }

    public function columns(): array
    {
        return [
            new Column('date', 'Date', Column::DATE, 9),
            new Column('store', 'Store', width: 10),
            new Column('medicine', 'Medicine', width: 17),
            new Column('batch', 'Batch', width: 7),
            new Column('type', 'Movement', width: 9),
            new Column('in', 'In', Column::NUMBER, 4.5),
            new Column('out', 'Out', Column::NUMBER, 4.5),
            new Column('balance', 'Balance after', Column::NUMBER, 6),
            new Column('unit_cost', 'Unit cost', Column::MONEY, 6),
            new Column('reason', 'Reason', width: 11),
            new Column('by', 'By', width: 8),
        ];
    }

    public function query(ExportFilters $filters): Builder
    {
        $query = StockMovement::query()->with([
            'medicine:id,generic_name,brand_name,strength,dosage_form',
            'batch:id,batch_number',
            'store:id,name',
        ]);

        $this->atBranches($query, 'location_id', $filters);
        $this->between($query, 'movement_date', $filters);

        return $query
            ->when($filters->status, fn (Builder $q, string $type) => $q->where('movement_type', $type))
            ->when($filters->like(), fn (Builder $q, string $like) => $q->where(fn (Builder $inner) => $inner
                ->whereHas('batch', fn (Builder $batch) => $batch->where('batch_number', 'ILIKE', $like))
                ->orWhereHas('medicine', fn (Builder $medicine) => $medicine
                    ->where('brand_name', 'ILIKE', $like)
                    ->orWhere('generic_name', 'ILIKE', $like))));
    }

    public function order(Builder $query): Builder
    {
        return $query->orderByDesc('movement_date')->orderByDesc('id');
    }

    public function sortable(): array
    {
        return ['date' => 'movement_date', 'type' => 'movement_type', 'balance' => 'quantity_after'];
    }

    /** @param  StockMovement  $record */
    public function row(Model $record): array
    {
        $change = (int) $record->quantity_after - (int) $record->quantity_before;

        return [
            'date' => $this->dateTime($record->movement_date),
            'store' => $record->store?->name,
            'medicine' => $record->medicine?->displayName(),
            'batch' => $record->batch?->batch_number,
            'type' => $this->words($record->movement_type),
            'in' => $change > 0 ? $change : null,
            'out' => $change < 0 ? -$change : null,
            'balance' => (int) $record->quantity_after,
            'unit_cost' => $record->unit_cost !== null ? $this->money($record->unit_cost) : null,
            'reason' => $record->reason,
            'by' => $record->performed_by_name,
        ];
    }
}
