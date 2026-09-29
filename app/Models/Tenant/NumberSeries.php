<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What one branch's invoices, receipts or refunds are numbered with.
 *
 * THE NUMBERS THEMSELVES ARE NOT TAKEN HERE. A BEFORE INSERT trigger on
 * `invoices` and `invoice_payments` takes each one inside the INSERT, from
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

    public const TYPES = [self::INVOICE, self::RECEIPT, self::REFUND];

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
