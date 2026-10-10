<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of a return: how many of which sold line, from which batch, at what value. */
class PharmacySaleReturnItem extends Model
{
    protected $connection = 'organization';

    protected $fillable = [
        'pharmacy_sale_return_id',
        'pharmacy_sale_item_id',
        'medicine_batch_id',
        'quantity',
        'taxable_amount',
        'tax_amount',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'taxable_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(PharmacySaleItem::class, 'pharmacy_sale_item_id');
    }
}
