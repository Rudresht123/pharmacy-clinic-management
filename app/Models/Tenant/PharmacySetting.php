<?php

namespace App\Models\Tenant;

use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How this organisation's pharmacy bills, prices and warns.
 *
 * One row per organisation — the database enforces that, not a convention —
 * created by the migration so it is always there to read. Nothing here is
 * per store: a chain bills the same way at every counter, and anything that
 * genuinely differs by counter (its licence, its name on the bill) already
 * lives on the store.
 *
 * Every value a sale depends on is COPIED onto the sale when it is made.
 * Changing the tax treatment or the price basis tomorrow must never change
 * what a bill printed today said.
 */
class PharmacySetting extends Model
{
    use RecordsHistory;

    /** What the counter charges: the printed ceiling, or the store's own price. */
    public const PRICE_MRP = 'mrp';

    public const PRICE_SELLING = 'selling';

    public const PRICE_BASES = [self::PRICE_MRP, self::PRICE_SELLING];

    public const CASH = 'cash';

    public const CARD = 'card';

    public const UPI = 'upi';

    public const BANK_TRANSFER = 'bank_transfer';

    public const CREDIT = 'credit';

    public const OTHER = 'other';

    /** Every value the database CHECK permits. */
    public const PAYMENT_METHODS = [
        self::CASH, self::CARD, self::UPI, self::BANK_TRANSFER, self::CREDIT, self::OTHER,
    ];

    protected $connection = 'organization';

    protected $table = 'pharmacy_settings';

    protected $fillable = [
        'invoice_prefix',
        'round_off_enabled',
        'price_basis',
        'prices_include_tax',
        'expiry_warning_days',
        'allow_walk_in',
        'credit_sales_enabled',
        'require_prescription',
        'default_payment_method',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'round_off_enabled' => 'boolean',
            'prices_include_tax' => 'boolean',
            'expiry_warning_days' => 'integer',
            'allow_walk_in' => 'boolean',
            'credit_sales_enabled' => 'boolean',
            'require_prescription' => 'boolean',
        ];
    }

    /**
     * The organisation's settings.
     *
     * `firstOrCreate` rather than `firstOrFail` for the one case the
     * migration cannot cover: a tenant database restored from before this
     * table existed. The defaults are the table's own.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate([]);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    protected function historyLabel(): ?string
    {
        return 'Pharmacy settings';
    }
}
