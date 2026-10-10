<?php

namespace App\Models\Tenant;

use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A clinic invoice.
 *
 * Generic: `invoice_items.source_type` decides what a line is, so the same
 * document carries a consultation, a pharmacy line, a lab charge or a custom
 * service. `trigger` records which of the billing settings' triggers produced
 * it — a change to the setting later does not rewrite the invoice.
 *
 * A financial record. Nothing here is deleted (the Postgres DELETE trigger in
 * the migration refuses it); a mistake is cancelled, which stamps who did it
 * and why.
 */
class Invoice extends Model
{
    use RecordsHistory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PARTIALLY_PAID = 'partially_paid';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELLED = 'cancelled';

    /** Settled, and every rupee taken was handed back. */
    public const STATUS_REFUNDED = 'refunded';

    /** Settled, and some of what was taken was handed back. */
    public const STATUS_PARTIALLY_REFUNDED = 'partially_refunded';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING,
        self::STATUS_PARTIALLY_PAID,
        self::STATUS_PAID,
        self::STATUS_CANCELLED,
        self::STATUS_REFUNDED,
        self::STATUS_PARTIALLY_REFUNDED,
    ];

    public const UNPAID = 'unpaid';

    public const PARTIAL = 'partial';

    public const PAID = 'paid';

    public const PAYMENT_STATUSES = [self::UNPAID, self::PARTIAL, self::PAID];

    /** The states a bill still owes money on — the till's "outstanding" list. */
    public const OWES_MONEY = [self::STATUS_PENDING, self::STATUS_PARTIALLY_PAID];

    /**
     * What is still owed, in SQL, for sums and sorts over many bills.
     *
     * The same arithmetic as outstanding(): money handed back credits the
     * bill, so a refund never turns into a debt.
     */
    public const OWED_SQL = '(total_amount - paid_amount - refunded_amount)';

    /*
    |--------------------------------------------------------------------------
    | What kind of bill this is
    |--------------------------------------------------------------------------
    |
    | A VISIT invoice is the OPD's bill for a visit: the doctor's fee and the
    | per-visit charges, drawn when the organisation's OPD timing says so.
    | There is at most one live one per appointment, which a partial unique
    | index enforces. (Before each department billed for itself it could also
    | carry lab and pharmacy lines; such invoices are left as they were.)
    |
    | A LAB invoice is the lab's bill for one order — its priced tests — drawn
    | at the lab's own timing. One live one per order, by the same kind of
    | index. The pharmacy never draws an invoice: its bill is the sale.
    |
    | REGISTRATION stands alone. It is taken at the desk before there is a
    | visit to attach it to, and the patient walks away with the receipt — so
    | sweeping it into a visit that may not happen for another week would be
    | holding somebody's money against a document they cannot see.
    |
    | MANUAL is the exception hatch: a certificate, a records fee, anything
    | the workflow did not produce. Never swept into a visit either.
    */
    public const KIND_VISIT = 'visit';

    public const KIND_REGISTRATION = 'registration';

    public const KIND_MANUAL = 'manual';

    public const KIND_LAB = 'lab';

    public const KINDS = [self::KIND_VISIT, self::KIND_REGISTRATION, self::KIND_MANUAL, self::KIND_LAB];

    /** Which department's book a bill belongs to — the finance view's grouping. */
    public const KIND_MODULE = [
        self::KIND_VISIT => 'opd',
        self::KIND_REGISTRATION => 'opd',
        self::KIND_MANUAL => 'opd',
        self::KIND_LAB => 'laboratory',
    ];

    protected $connection = 'organization';

    protected $fillable = [
        'location_id',
        'customer_id',
        'walk_in_name',
        'walk_in_phone',
        'appointment_id',
        'consultation_id',
        'lab_order_id',
        'doctor_id',
        'invoice_date',
        'status',
        'trigger',
        'kind',
        'currency_code',
        'prices_include_tax',
        'subtotal',
        'discount_amount',
        'tax_amount',
        'round_off',
        'total_amount',
        'paid_amount',
        'refunded_amount',
        'payment_status',
        'notes',
        'terms',
        'created_by',
        'created_by_name',
        'finalized_at',
        'finalized_by',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'datetime',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'round_off' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'refunded_amount' => 'decimal:2',
            'prices_include_tax' => 'boolean',
            'cancelled_at' => 'datetime',
            'finalized_at' => 'datetime',
        ];
    }

    public function labOrder(): BelongsTo
    {
        return $this->belongsTo(LabOrder::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class);
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /**
     * Still collecting charges — not yet a bill anybody may pay.
     *
     * The consolidation rule in one method. A visit that has had its
     * consultation billed but is still waiting on a lab result is a draft,
     * and asking the patient for money now would mean asking again later.
     */
    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isFinalized(): bool
    {
        return $this->finalized_at !== null;
    }

    /** Whether more charges may still be appended to it. */
    public function acceptsMoreLines(): bool
    {
        return $this->isDraft() && ! $this->isCancelled();
    }

    public function isSettled(): bool
    {
        return $this->payment_status === self::PAID;
    }

    public function owesMoney(): bool
    {
        return in_array($this->status, self::OWES_MONEY, true)
            && $this->payment_status !== self::PAID;
    }

    /**
     * What is still owed.
     *
     * Money handed back is subtracted with what was paid: a refund credits
     * the bill rather than reopening it, so ₹500 paid and ₹200 refunded on a
     * ₹500 bill owes nothing.
     */
    public function outstanding(): float
    {
        return round(max(
            0.0,
            (float) $this->total_amount - (float) $this->paid_amount - (float) $this->refunded_amount,
        ), 2);
    }

    /**
     * Whether this bill's prices were tax-inclusive, as it was drawn.
     *
     * Read from the bill, never from today's settings — falling back to them
     * only for a row that somehow predates the column being filled.
     */
    public function pricesIncludeTax(): bool
    {
        return $this->prices_include_tax ?? (bool) BillingSetting::current()->prices_include_tax;
    }

    /** Whether any money has ever moved against this bill, either way. */
    public function hasMoneyMoved(): bool
    {
        return $this->payments()->exists();
    }

    public function scopeAtBranch(Builder $query, int $locationId): Builder
    {
        return $query->where('location_id', $locationId);
    }

    public function scopeForCustomer(Builder $query, int $customerId): Builder
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('status', self::OWES_MONEY)->where('payment_status', '!=', self::PAID);
    }

    /** The consolidated bill for one visit, while it still stands. */
    public function scopeLiveForVisit(Builder $query, int $appointmentId): Builder
    {
        return $query->where('appointment_id', $appointmentId)
            ->where('kind', self::KIND_VISIT)
            ->whereNot('status', self::STATUS_CANCELLED);
    }

    /** The lab's bill for one order, while it still stands. */
    public function scopeLiveForLabOrder(Builder $query, int $labOrderId): Builder
    {
        return $query->where('lab_order_id', $labOrderId)
            ->where('kind', self::KIND_LAB)
            ->whereNot('status', self::STATUS_CANCELLED);
    }

    protected function historyLabel(): ?string
    {
        return $this->invoice_number;
    }
}
