<?php

namespace App\Models\Tenant;

use App\Models\Tenant\Concerns\IsLabList;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A grouping of tests inside a department — "Liver function", "Hormones". */
class LabTestCategory extends Model
{
    use IsLabList;

    public const LABEL = 'name';

    protected $connection = 'organization';

    protected $table = 'lab_test_categories';

    protected $fillable = ['department_id', 'name', 'code', 'is_active', 'position'];

    protected function casts(): array
    {
        return ['department_id' => 'integer'];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(LabDepartment::class, 'department_id')->withTrashed();
    }
}
