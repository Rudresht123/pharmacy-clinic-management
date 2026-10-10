<?php

namespace App\Models\Tenant;

use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Goods brought back to the counter against a bill — a credit note.
 *
 * What came back, valued as the bill valued it, and what was handed back for
 * it. The bill itself is not edited: its `returned_amount` grows, and the
 * difference between what it was for and what is now owed is this record.
 *
 * `return_number` is the database's, taken inside the INSERT from the
 * branch's pharmacy-return series (GGN/PHR/26-27/00001). Never deleted.
 */
class PharmacySaleReturn extends Model
{
    use RecordsHistory;

    protected $connection = 'organization';

    protected $fillable = [
        'pharmacy_sale_id',
        'pharmacy_store_id',
        'location_id',
        'return_date',
        'reason',
        'taxable_amount',
        'tax_amount',
        'total_amount',
        'refund_amount',
        'idempotency_key',
        'created_by',
        'created_by_name',
    ];

    protected function casts(): array
    {
        return [
            'return_date' => 'datetime',
            'taxable_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'refund_amount' => 'decimal:2',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(PharmacySale::class, 'pharmacy_sale_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PharmacySaleReturnItem::class);
    }

    protected function historyLabel(): ?string
    {
        return $this->return_number;
    }

    /** @return list<string> */
    protected function historyExcept(): array
    {
        return ['idempotency_key'];
    }
}
