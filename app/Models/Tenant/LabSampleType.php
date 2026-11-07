<?php

namespace App\Models\Tenant;

use App\Models\Tenant\Concerns\IsLabList;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What is taken from the patient — serum, whole blood, urine.
 *
 * Its default container is what the collection screen suggests; a test can
 * still ask for a different one.
 */
class LabSampleType extends Model
{
    use IsLabList;

    public const LABEL = 'name';

    protected $connection = 'organization';

    protected $table = 'lab_sample_types';

    protected $fillable = ['name', 'code', 'default_container_id', 'handling_notes', 'is_active', 'position'];

    protected function casts(): array
    {
        return ['default_container_id' => 'integer'];
    }

    public function defaultContainer(): BelongsTo
    {
        return $this->belongsTo(LabContainer::class, 'default_container_id')->withTrashed();
    }
}
