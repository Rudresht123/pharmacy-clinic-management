<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a goods received note, in base units and per-unit prices.
 *
 * Append-only, by trigger: cancelling the note writes compensating ledger
 * rows and leaves its lines exactly as they were received.
 */
class StockInwardItem extends Model
{
    protected $connection = 'organization';

    protected $fillable = [
        'stock_inward_id',
        'medicine_id',
        'medicine_batch_id',
        'batch_number',
        'expiry_date',
        'manufacture_date',
        'pack_size',
        'quantity',
        'free_quantity',
        'purchase_price',
        'selling_price',
        'mrp',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
            'manufacture_date' => 'date',
            'pack_size' => 'integer',
            'quantity' => 'integer',
            'free_quantity' => 'integer',
            'purchase_price' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'mrp' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function inward(): BelongsTo
    {
        return $this->belongsTo(StockInward::class, 'stock_inward_id');
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class)->withTrashed();
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(MedicineBatch::class, 'medicine_batch_id')->withTrashed();
    }
}
