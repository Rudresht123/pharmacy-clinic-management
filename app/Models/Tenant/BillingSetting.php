<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/**
 * How this organisation bills.
 *
 * One row, ever. The unique index on `((true))` in the migration makes a
 * second an error rather than a race. `current()` reads it, or seeds a
 * default the first time — so a fresh organisation billing at once still
 * finds a set of rules to bill by.
 */
class BillingSetting extends Model
{
    public const TRIGGER_CHECKIN = 'checkin';

    public const TRIGGER_CONSULTATION = 'consultation';

    public const TRIGGER_PHARMACY = 'pharmacy';

    public const TRIGGER_LABORATORY = 'laboratory';

    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGERS = [
        self::TRIGGER_CHECKIN,
        self::TRIGGER_CONSULTATION,
        self::TRIGGER_PHARMACY,
        self::TRIGGER_LABORATORY,
        self::TRIGGER_MANUAL,
    ];

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
}
