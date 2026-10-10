<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money taken against a bill — or handed back on one.
 *
 * A row per tender, so a bill settled ₹500 cash and ₹200 by UPI is two rows
 * — which is what a till reconciliation needs, and what one `payment_method`
 * column on the sale could never answer.
 *
 * `is_refund` marks money going BACK: on a cancelled bill, or for goods
 * returned. Amounts are always positive; the flag says which way they moved,
 * the same convention as the clinic's own payments. A refund points at the
 * payment it undoes, so "how much of this payment is left to give back" is
 * always answerable.
 *
 * Credit is the absence of payment, not a method that takes money: a credit
 * sale is a bill with less paid against it than it is worth, and the
 * difference is what the customer owes.
 */
class PharmacySalePayment extends Model
{
    protected $connection = 'organization';

    public const CASH = 'cash';

    public const CARD = 'card';

    public const UPI = 'upi';

    public const BANK_TRANSFER = 'bank_transfer';

    public const ONLINE = 'online';

    public const CHEQUE = 'cheque';

    public const OTHER = 'other';

    /**
     * Every value the database CHECK permits — the organisation's full list,
     * so the counter can take whatever Billing settings say it accepts.
     */
    public const METHODS = [
        self::CASH, self::CARD, self::UPI, self::BANK_TRANSFER, self::ONLINE, self::CHEQUE, self::OTHER,
    ];

    /** A row's effect on money held, in SQL: refunds count against. */
    public const NET_SQL = '(CASE WHEN is_refund THEN -amount ELSE amount END)';

    protected $fillable = [
        'pharmacy_sale_id',
        'method',
        'amount',
        'is_refund',
        'refunds_payment_id',
        'pharmacy_sale_return_id',
        'reference',
        'notes',
        'paid_at',
        'created_by',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'is_refund' => 'boolean',
            'paid_at' => 'datetime',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(PharmacySale::class, 'pharmacy_sale_id');
    }

    public function refunded(): BelongsTo
    {
        return $this->belongsTo(self::class, 'refunds_payment_id');
    }
}
