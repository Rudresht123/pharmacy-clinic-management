<?php

namespace App\Models\Tenant;

use App\Models\Tenant\Concerns\IsLabList;
use Illuminate\Database\Eloquent\Model;

/** Why a sample was turned away — "Haemolysed", "Insufficient quantity". */
class LabRejectionReason extends Model
{
    use IsLabList;

    public const LABEL = 'reason';

    protected $connection = 'organization';

    protected $table = 'lab_rejection_reasons';

    protected $fillable = ['reason', 'requires_recollection', 'is_active', 'position'];

    protected function casts(): array
    {
        return ['requires_recollection' => 'boolean'];
    }
}
