<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/**
 * The last number a series issued in one financial year.
 *
 * Read-only from PHP: the trigger that takes a number is the only writer, and
 * it writes inside the same transaction as the document, so a bill that rolls
 * back hands its number back.
 */
class NumberSeriesCounter extends Model
{
    protected $connection = 'organization';

    protected $table = 'number_series_counters';

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'number_series_id';

    protected function casts(): array
    {
        return [
            'last_number' => 'integer',
        ];
    }
}
