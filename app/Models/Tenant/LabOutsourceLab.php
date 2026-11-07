<?php

namespace App\Models\Tenant;

use App\Models\Tenant\Concerns\IsLabList;
use Illuminate\Database\Eloquent\Model;

/** A partner laboratory tests are sent out to, and their results come back from. */
class LabOutsourceLab extends Model
{
    use IsLabList;

    public const LABEL = 'name';

    protected $connection = 'organization';

    protected $table = 'lab_outsource_labs';

    protected $fillable = [
        'name', 'code', 'contact_person', 'phone', 'email', 'address', 'notes', 'is_active', 'position',
    ];
}
