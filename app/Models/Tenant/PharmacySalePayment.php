<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money taken against a bill.
 *
 * A row per tender, so a bill settled ₹500 cash and ₹200 by UPI is two rows
 * — which is what a till reconciliation needs, and what one `payment_method`
 * column on the sale could never answer.
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

    public const OTHER = 'other';

    /** Every value the database CHECK permits. */
    public const METHODS = [self::CASH, self::CARD, self::UPI, self::BANK_TRANSFER, self::OTHER];

    protected $fillable = [
        'pharmacy_sale_id',
        'method',
        'amount',
        'reference',
        'paid_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(PharmacySale::class, 'pharmacy_sale_id');
    }
}
