<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One capability held by one role.
 *
 * A row each rather than a jsonb array on `roles`, so "which roles can delete a
 * patient" is a query — which is the question somebody asks after something has
 * gone wrong, and the worst time to be unable to answer it.
 *
 * Deliberately not recording history. The row is meaningless on its own, and
 * Role's own log already names the role and the change; logging both would put
 * the same edit in the activity list several times over.
 */
class RoleCapability extends Model
{
    /**
     * Every tenant table lives in a different physical database, filled in
     * at runtime by TenantConnectionService — this model must never be
     * queried against whatever the default connection happens to be.
     */
    protected $connection = 'organization';

    protected $table = 'role_capabilities';

    protected $fillable = [
        'role_id',
        'capability',
    ];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
