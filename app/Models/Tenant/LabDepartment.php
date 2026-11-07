<?php

namespace App\Models\Tenant;

use App\Models\Tenant\Concerns\IsLabList;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A section of the lab — Haematology, Biochemistry, Microbiology.
 *
 * What a test is filed under, what a worklist is split by and what a report
 * groups its results under.
 */
class LabDepartment extends Model
{
    use IsLabList;

    public const LABEL = 'name';

    protected $connection = 'organization';

    protected $table = 'lab_departments';

    protected $fillable = ['name', 'code', 'description', 'is_active', 'position'];

    public function categories(): HasMany
    {
        return $this->hasMany(LabTestCategory::class, 'department_id');
    }
}
