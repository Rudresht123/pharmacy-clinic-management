<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line on an invoice.
 *
 * `source_type` names where the line came from — a consultation, a pharmacy
 * sale item, a lab test, or a service picked from the catalogue. `source_id`
 * points at the row when there is one; `custom` lines carry no source and
 * exist purely as free-text charges (a certificate, an out-of-pocket
 * expense, a note the counter typed).
 */
class InvoiceItem extends Model
{
    public const SOURCE_CONSULTATION = 'consultation';

    public const SOURCE_PHARMACY_SALE_ITEM = 'pharmacy_sale_item';

    public const SOURCE_LAB_TEST = 'lab_test';

    public const SOURCE_PROCEDURE = 'procedure';

    public const SOURCE_SERVICE = 'service';

    public const SOURCE_CUSTOM = 'custom';

    public const SOURCES = [
        self::SOURCE_CONSULTATION,
        self::SOURCE_PHARMACY_SALE_ITEM,
        self::SOURCE_LAB_TEST,
        self::SOURCE_PROCEDURE,
        self::SOURCE_SERVICE,
        self::SOURCE_CUSTOM,
    ];

    protected $connection = 'organization';

    protected $fillable = [
        'invoice_id',
        'source_type',
        'source_id',
        'billable_service_id',
        'description',
        /* The tax code, snapshotted at billing time — see the migration. */
        'hsn_code',
        'quantity',
        'unit_price',
        'discount_percent',
        'discount_amount',
        'tax_percent',
        'tax_amount',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_percent' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(BillableService::class, 'billable_service_id');
    }
}
