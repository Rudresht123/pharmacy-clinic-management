<?php

namespace App\Models\Tenant;

use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A bill.
 *
 * The same record whether it was rung up at a counter or dispensed against a
 * prescription — a dispensing is this, carrying `prescription_id`. Never
 * deleted: a mistake is cancelled, which puts the stock back and leaves the
 * bill where it was.
 */
class PharmacySale extends Model
{
    use RecordsHistory;

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    /** Every value the database CHECK permits. */
    public const STATUSES = [self::COMPLETED, self::CANCELLED];

    public const PAID = 'paid';

    public const PARTIAL = 'partial';

    public const UNPAID = 'unpaid';

    /**
     * A visit invoice took this sale's money over.
     *
     * LEGACY. Visit billing once folded dispensed medicines into the visit's
     * invoice and marked the sale so; each department now bills for itself
     * and nothing writes this any more. Rows that carry it are read as "owed
     * on that invoice, not here" — the pharmacy is owed nothing for them, and
     * the finance view counts their value once, on the invoice.
     */
    public const BILLED_VIA_INVOICE = 'billed_via_invoice';

    /**
     * What is still owed on a bill, in SQL — goods taken back are no longer
     * owed for, and a sale whose money an invoice took over owes nothing here.
     */
    public const DUE_SQL = "(CASE WHEN payment_status = 'billed_via_invoice' THEN 0 "
        .'ELSE GREATEST(total_amount - returned_amount - paid_amount, 0) END)';

    public const PAYMENT_STATUSES = [
        self::PAID,
        self::PARTIAL,
        self::UNPAID,
        self::BILLED_VIA_INVOICE,
    ];

    protected $connection = 'organization';

    protected $fillable = [
        'pharmacy_store_id',
        'location_id',
        'customer_id',
        'walk_in_name',
        'walk_in_phone',
        'prescription_id',

        /*
         * Which visit this bill belongs to.
         *
         * Set by SalesService from the prescription, never from a request —
         * the client has no business asserting which visit it is billing.
         * Null for every counter sale, which is all of them at a standalone
         * medical store.
         */
        'appointment_id',

        'doctor_id',
        'sale_date',
        'price_basis',
        'prices_include_tax',
        'subtotal',
        'discount_amount',
        'tax_amount',
        'round_off',
        'total_amount',
        'paid_amount',
        'returned_amount',
        'payment_status',
        'notes',
        'idempotency_key',
        'created_by',
        'created_by_name',
    ];

    protected function casts(): array
    {
        return [
            'sale_date' => 'datetime',
            'cancelled_at' => 'datetime',
            'prices_include_tax' => 'boolean',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'round_off' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'returned_amount' => 'decimal:2',
        ];
    }

    /** What came back over the counter, newest last. */
    public function returns(): HasMany
    {
        return $this->hasMany(PharmacySaleReturn::class)->orderBy('id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(PharmacyStore::class, 'pharmacy_store_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PharmacySaleItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PharmacySalePayment::class);
    }

    /**
     * What is still owed on it — nothing, on a bill paid in full.
     *
     * Goods taken back are subtracted: a ₹300 bill with ₹100 returned and
     * ₹200 paid owes nothing. A sale whose money a visit invoice took over
     * (legacy) owes nothing here either.
     */
    public function amountDue(): float
    {
        if ($this->payment_status === self::BILLED_VIA_INVOICE) {
            return 0.0;
        }

        return round(max(
            0.0,
            (float) $this->total_amount - (float) $this->returned_amount - (float) $this->paid_amount,
        ), 2);
    }

    /** Who it was sold to, however they were recorded. */
    public function buyerName(): string
    {
        return $this->customer?->name ?? (string) ($this->walk_in_name ?: 'Walk-in customer');
    }

    public function isCancelled(): bool
    {
        return $this->status === self::CANCELLED;
    }

    /** @param  Builder<PharmacySale>  $query */
    public function scopeCompleted(Builder $query): void
    {
        $query->where('status', self::COMPLETED);
    }

    protected function historyLabel(): ?string
    {
        return $this->sale_number;
    }

    /**
     * The bill's own lines stay out of the audit log: what somebody bought is
     * on the bill, and the log records that it was made, changed or cancelled.
     *
     * @return list<string>
     */
    protected function historyExcept(): array
    {
        return ['idempotency_key'];
    }
}
