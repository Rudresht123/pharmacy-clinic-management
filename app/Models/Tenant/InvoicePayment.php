<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money against an invoice.
 *
 * A payment is a positive amount and a refund is one too — `is_refund` says
 * the direction it moved. A refund carries `refunds_payment_id`, so what
 * undid what stays traceable.
 *
 * `receipt_number` is deliberately not fillable: the database takes it inside
 * the INSERT, from the branch's receipt or refund series (GGN/RCP/26-27/00001)
 * — see NumberSeries. Re-read the row to see it.
 */
class InvoicePayment extends Model
{
    public const CASH = 'cash';

    public const CARD = 'card';

    public const UPI = 'upi';

    public const BANK_TRANSFER = 'bank_transfer';

    public const ONLINE = 'online';

    public const CHEQUE = 'cheque';

    public const OTHER = 'other';

    public const METHODS = [
        self::CASH,
        self::CARD,
        self::UPI,
        self::BANK_TRANSFER,
        self::ONLINE,
        self::CHEQUE,
        self::OTHER,
    ];

    protected $connection = 'organization';

    protected $fillable = [
        'invoice_id',
        'method',
        'amount',
        'reference',
        'notes',
        'paid_at',
        'created_by',
        'is_refund',
        'refunds_payment_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'is_refund' => 'boolean',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function refunded(): BelongsTo
    {
        return $this->belongsTo(self::class, 'refunds_payment_id');
    }
}
