<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What one branch's invoices, receipts or refunds are numbered with.
 *
 * THE NUMBERS THEMSELVES ARE NOT TAKEN HERE. A BEFORE INSERT trigger on
 * `invoices`, `invoice_payments`, `pharmacy_sales` and `pharmacy_sale_returns`
 * takes each one inside the INSERT, from
 * `hms_series_number()` — see the migration that created this table. A row
 * appears here the first time a branch issues that kind of document, carrying
 * the default prefix; all anybody does with this model is change the prefix,
 * and read how far the counters have got.
 */
class NumberSeries extends Model
{
    public const INVOICE = 'invoice';

    public const RECEIPT = 'receipt';

    public const REFUND = 'refund';

    /** A pharmacy bill — GGN/PH/26-27/00001. */
    public const PHARMACY_SALE = 'pharmacy_sale';

    /** Goods taken back at the pharmacy — GGN/PHR/26-27/00001. */
    public const PHARMACY_RETURN = 'pharmacy_return';

    public const TYPES = [self::INVOICE, self::RECEIPT, self::REFUND, self::PHARMACY_SALE, self::PHARMACY_RETURN];

    /** The pharmacy's own documents — shown only where the pharmacy runs. */
    public const PHARMACY_TYPES = [self::PHARMACY_SALE, self::PHARMACY_RETURN];

    protected $connection = 'organization';

    protected $table = 'number_series';

    protected $fillable = [
        'location_id',
        'document_type',
        'prefix',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** How far this series has got, one row per financial year. */
    public function counters(): HasMany
    {
        return $this->hasMany(NumberSeriesCounter::class);
    }
}
