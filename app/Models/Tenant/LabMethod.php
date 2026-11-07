<?php

namespace App\Models\Tenant;

use App\Models\Tenant\Concerns\IsLabList;
use Illuminate\Database\Eloquent\Model;

/** How a test is run — "Chemiluminescence", "Microscopy". Printed beside a result. */
class LabMethod extends Model
{
    use IsLabList;

    public const LABEL = 'name';

    protected $connection = 'organization';

    protected $table = 'lab_methods';

    protected $fillable = ['name', 'description', 'is_active', 'position'];
}
