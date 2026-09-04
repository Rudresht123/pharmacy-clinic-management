<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A branch's decision about one module.
 *
 * A sparse override: a row exists only where the owner switched a module off at
 * that branch. No row means the branch uses whatever the organization holds,
 * which is why an organization that never opens the screen behaves exactly as
 * it did before this table existed, and why a module bought next year is live
 * everywhere at once instead of reaching nobody.
 *
 * `module_key` names a ModuleRegistry module and is not a foreign key — the
 * catalogue lives in the master database and this row lives in a tenant one.
 */
class LocationModule extends Model
{
    /**
     * Every tenant table lives in a different physical database, filled in
     * at runtime by TenantConnectionService — this model must never be
     * queried against whatever the default connection happens to be.
     */
    protected $connection = 'organization';

    protected $table = 'location_modules';

    protected $fillable = [
        'location_id',
        'module_key',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
