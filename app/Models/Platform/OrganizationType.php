<?php

namespace App\Models\Platform;

use App\Models\Record;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrganizationType extends Record
{
    use SoftDeletes;

    protected $table = 'organization_types';

    protected $fillable = [
        'name',
        'slug',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Organizations classified under this type.
     */
    public function organizations(): HasMany
    {
        return $this->hasMany(Organization::class, 'organization_type_id');
    }
}
