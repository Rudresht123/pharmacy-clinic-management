<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line on a bill.
 *
 * Everything it says about the item, the batch and the tax is a copy taken
 * when the bill was made. A catalogue entry renamed next month, a batch
 * emptied and reused, a GST rate that changes — none of it rewrites what
 * this line already said.
 */
class PharmacySaleItem extends Model
{
    protected $connection = 'organization';

    protected $fillable = [
        'pharmacy_sale_id',
        'medicine_id',
        'medicine_batch_id',
        'prescription_item_id',
        'item_name_snapshot',
        'hsn_code_snapshot',
        'batch_number_snapshot',
        'expiry_date_snapshot',
        'quantity',
        'mrp',
        'unit_price',
        'discount_percent',
        'discount_amount',
        'tax_rate',
        'taxable_amount',
        'tax_amount',
        'line_total',
        'unit_cost',
    ];

    protected function casts(): array
    {
        return [
            'expiry_date_snapshot' => 'date',
            'quantity' => 'integer',
            'mrp' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'taxable_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
            'unit_cost' => 'decimal:2',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(PharmacySale::class, 'pharmacy_sale_id');
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(MedicineBatch::class, 'medicine_batch_id');
    }

    public function prescriptionItem(): BelongsTo
    {
        return $this->belongsTo(PrescriptionItem::class);
    }

    /** What the store made on this line, at what it paid for the stock. */
    public function margin(): ?float
    {
        if ($this->unit_cost === null) {
            return null;
        }

        return round((float) $this->taxable_amount - ((float) $this->unit_cost * $this->quantity), 2);
    }
}
