<?php

namespace App\Models\Tenant;

use App\Models\Tenant\Concerns\IsLabList;
use Illuminate\Database\Eloquent\Model;

/**
 * What a sample is drawn into — "EDTA tube", "Plain tube", "Sterile container".
 *
 * The cap colour is a closed list so the collection screen and the label can
 * draw it; the collector reaches for a colour, not a name.
 */
class LabContainer extends Model
{
    use IsLabList;

    public const LABEL = 'name';

    /** Every value the database CHECK permits. */
    public const CAP_COLOURS = [
        'red', 'gold', 'yellow', 'lavender', 'pink', 'light_blue',
        'royal_blue', 'grey', 'green', 'black', 'orange', 'white', 'clear',
    ];

    protected $connection = 'organization';

    protected $table = 'lab_containers';

    protected $fillable = ['name', 'cap_colour', 'additive', 'volume_ml', 'is_active', 'position'];

    protected function casts(): array
    {
        return ['volume_ml' => 'decimal:2'];
    }
}
