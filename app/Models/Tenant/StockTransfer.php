<?php

namespace App\Models\Tenant;

use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Stock moved from one store to another, out and in within one transaction.
 *
 * Immediate in v1 — there is no "in transit". Append-only, by trigger.
 */
class StockTransfer extends Model
{
    use RecordsHistory;

    public const COMPLETED = 'completed';

    protected $connection = 'organization';

    protected $fillable = [
        'from_store_id',
        'to_store_id',
        'notes',
        'idempotency_key',
        'created_by',
        'created_by_name',
    ];

    /** @return list<string> */
    protected function historyExcept(): array
    {
        return ['idempotency_key'];
    }

    protected function historyLabel(): ?string
    {
        return $this->transfer_number;
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class);
    }

    public function fromStore(): BelongsTo
    {
        return $this->belongsTo(PharmacyStore::class, 'from_store_id')->withTrashed();
    }

    public function toStore(): BelongsTo
    {
        return $this->belongsTo(PharmacyStore::class, 'to_store_id')->withTrashed();
    }
}
