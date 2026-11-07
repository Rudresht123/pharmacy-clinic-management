<?php

namespace App\Models\Tenant;

use App\Models\Tenant\Concerns\IsLabList;
use Illuminate\Database\Eloquent\Model;

/**
 * What a result is measured in — g/dL, mmol/L, cells/µL.
 *
 * The symbol is compared WITH case: mU/L and MU/L are different units.
 */
class LabUnit extends Model
{
    use IsLabList;

    public const LABEL = 'symbol';

    protected $connection = 'organization';

    protected $table = 'lab_units';

    protected $fillable = ['symbol', 'name', 'is_active', 'position'];
}
