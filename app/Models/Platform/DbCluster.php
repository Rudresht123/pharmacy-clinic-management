<?php

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A physical PostgreSQL server a tenant database can live on. */
class DbCluster extends Model
{
    protected $fillable = [
        'name',
        'host',
        'port',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function tenantDatabases(): HasMany
    {
        return $this->hasMany(TenantDatabase::class);
    }
}
