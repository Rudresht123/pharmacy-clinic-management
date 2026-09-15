<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One batch's worth of a transfer. Append-only, by trigger. */
class StockTransferItem extends Model
{
    protected $connection = 'organization';

    protected $fillable = [
        'stock_transfer_id',
        'medicine_id',
        'source_batch_id',
        'destination_batch_id',
        'quantity',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class)->withTrashed();
    }

    public function sourceBatch(): BelongsTo
    {
        return $this->belongsTo(MedicineBatch::class, 'source_batch_id')->withTrashed();
    }

    public function destinationBatch(): BelongsTo
    {
        return $this->belongsTo(MedicineBatch::class, 'destination_batch_id')->withTrashed();
    }
}
