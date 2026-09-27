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
     * The clinic is collecting this, not the counter.
     *
     * A dispensing that belongs to a visit is billed on that visit's
     * consolidated invoice — consultation, lab and medicines on one document
     * — so the till must not also ask for it. The sale row still exists, and
     * still moved the stock; what it no longer does is own the money.
     *
     * Reports read this as "not outstanding here": the pharmacy is owed
     * nothing, because the billing counter is.
     */
    public const BILLED_VIA_INVOICE = 'billed_via_invoice';

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
        ];
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

    /** What is still owed on it — nothing, on a bill paid in full. */
    public function amountDue(): float
    {
        return round((float) $this->total_amount - (float) $this->paid_amount, 2);
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
