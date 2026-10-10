<?php

namespace App\Models\Tenant;

use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Model;

/**
 * How this organisation bills.
 *
 * One row, ever. The unique index on `((true))` in the migration makes a
 * second an error rather than a race. `current()` reads it, or seeds a
 * default the first time — so a fresh organisation billing at once still
 * finds a set of rules to bill by.
 *
 * ORGANISATION DEFAULTS, NOT A UNIVERSAL TRIGGER. Each department bills for
 * itself: `default_trigger` is when the OPD bills a visit, `lab_trigger` when
 * the lab bills an order, and the pharmacy bills at its own counter at the
 * moment of sale (its options live in PharmacySetting). Currency, tax
 * defaults, accepted payment methods and the payment and editing policies are
 * shared by all of them.
 *
 * Records its own history: changing how an organisation bills is exactly the
 * kind of change somebody later asks "who did that, and when".
 */
class BillingSetting extends Model
{
    use RecordsHistory;

    public const TRIGGER_CHECKIN = 'checkin';

    public const TRIGGER_CONSULTATION = 'consultation';

    /** Kept for invoices drawn under it; no longer an OPD timing. */
    public const TRIGGER_PHARMACY = 'pharmacy';

    /** "After the lab signs off" — the lab's timing now, not the OPD's. */
    public const TRIGGER_LABORATORY = 'laboratory';

    /** When the doctor orders the tests. */
    public const TRIGGER_LAB_ORDER = 'lab_order';

    public const TRIGGER_MANUAL = 'manual';

    /** Everything an invoice may record as having produced it. */
    public const TRIGGERS = [
        self::TRIGGER_CHECKIN,
        self::TRIGGER_CONSULTATION,
        self::TRIGGER_PHARMACY,
        self::TRIGGER_LABORATORY,
        self::TRIGGER_MANUAL,
        self::TRIGGER_LAB_ORDER,
    ];

    /** When the OPD bills a visit. */
    public const OPD_TRIGGERS = [
        self::TRIGGER_CHECKIN,
        self::TRIGGER_CONSULTATION,
        self::TRIGGER_MANUAL,
    ];

    /** When the lab bills an order. */
    public const LAB_TRIGGERS = [
        self::TRIGGER_LAB_ORDER,
        self::TRIGGER_LABORATORY,
        self::TRIGGER_MANUAL,
    ];

    /** What an organisation with no valid OPD timing gets: the common case. */
    public const DEFAULT_OPD_TRIGGER = self::TRIGGER_CONSULTATION;

    public const PAYMENT_FULL = 'full';

    public const PAYMENT_PARTIAL = 'partial';

    public const PAYMENT_CREDIT = 'credit';

    public const PAYMENT_BEHAVIOURS = [
        self::PAYMENT_FULL,
        self::PAYMENT_PARTIAL,
        self::PAYMENT_CREDIT,
    ];

    protected $connection = 'organization';

    protected $table = 'billing_settings';

    protected $fillable = [
        'default_trigger',
        'lab_trigger',
        'payment_methods',
        'payment_behaviour',
        'prices_include_tax',
        'default_tax_percent',
        'allow_edit_before_payment',
        'invoice_prefix',
        'currency_code',
        'currency_symbol',
        'terms',
        'footer',
    ];

    protected function casts(): array
    {
        return [
            'payment_methods' => 'array',
            'prices_include_tax' => 'boolean',
            'default_tax_percent' => 'decimal:2',
            'allow_edit_before_payment' => 'boolean',
        ];
    }

    /**
     * The organisation's rules, or the defaults if nobody has ever changed them.
     *
     * Seeds on first read: an organisation that has bought billing but never
     * opened the settings screen still bills, at the software's defaults,
     * rather than the first invoice attempt returning "no settings row".
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate([], []);
    }

    /**
     * When the OPD bills a visit, as the organisation chose it.
     *
     * READ, NOT REWRITTEN. An organisation that picked "after medicines" or
     * "after lab tests" when those were visit-wide timings still has that
     * value stored; it is no longer an OPD timing, so it reads as the common
     * default until somebody saves the screen again. Nothing is overwritten
     * behind their back.
     */
    public function opdTrigger(): string
    {
        return in_array($this->default_trigger, self::OPD_TRIGGERS, true)
            ? $this->default_trigger
            : self::DEFAULT_OPD_TRIGGER;
    }

    /** When the lab bills an order — `manual` for anybody who never chose. */
    public function labTrigger(): string
    {
        return in_array($this->lab_trigger, self::LAB_TRIGGERS, true)
            ? $this->lab_trigger
            : self::TRIGGER_MANUAL;
    }

    /** Whether a payment may leave part of a bill still owing. */
    public function allowsPartialPayment(): bool
    {
        return $this->payment_behaviour !== self::PAYMENT_FULL;
    }

    /**
     * Whether a registered patient may leave with a bill unpaid — the visit
     * closes, and the balance waits under Outstanding.
     */
    public function allowsCredit(): bool
    {
        return $this->payment_behaviour === self::PAYMENT_CREDIT;
    }

    /** Whether a tender is one this organisation accepts. */
    public function accepts(string $method): bool
    {
        return in_array($method, (array) $this->payment_methods, true);
    }

    protected function historyLabel(): ?string
    {
        return 'Billing settings';
    }
}
