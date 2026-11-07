<?php

namespace App\Models\Tenant;

use App\Models\Tenant\Concerns\IsLabList;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An analyser or instrument a result can name — the one it was run on.
 *
 * The only lab list with a branch: a machine sits somewhere. Recording it on
 * a result is what lets a lab trace every reading from a faulty run.
 */
class LabEquipment extends Model
{
    use IsLabList;

    public const LABEL = 'name';

    protected $connection = 'organization';

    protected $table = 'lab_equipment';

    protected $fillable = [
        'name', 'code', 'manufacturer', 'model', 'serial_no',
        'department_id', 'location_id', 'is_active', 'position',
    ];

    protected function casts(): array
    {
        return ['department_id' => 'integer', 'location_id' => 'integer'];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(LabDepartment::class, 'department_id')->withTrashed();
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class)->withTrashed();
    }
}
