<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One capability one branch has taken away from one of the organization's roles.
 *
 * A subtraction and never an addition — see the migration for why the column
 * that would allow the opposite deliberately does not exist.
 *
 * Deliberately not recording history of its own, like RoleCapability: the row
 * means nothing read alone, and the branch's whole decision is logged against
 * the role it customised, which is the sentence somebody actually needs.
 */
class BranchRoleCapability extends Model
{
    /**
     * Every tenant table lives in a different physical database, filled in
     * at runtime by TenantConnectionService — this model must never be
     * queried against whatever the default connection happens to be.
     */
    protected $connection = 'organization';

    protected $table = 'branch_role_capabilities';

    protected $fillable = [
        'location_id',
        'role_id',
        'capability',
        'created_by',
    ];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * What a branch has actually taken off a role, right now.
     *
     * Locked capabilities are excluded HERE rather than deleted when a lock is
     * applied. A lock is the organization changing its mind, and it must take
     * effect on every branch immediately — including the ones that had already
     * removed the capability, whose rows would otherwise go on applying until
     * somebody remembered to clean them up. Unlocking then restores the
     * branch's own decision rather than silently discarding it.
     *
     * @return list<string>
     */
    public static function removedAt(int $locationId, int $roleId): array
    {
        return static::query()
            ->where('location_id', $locationId)
            ->where('role_id', $roleId)
            ->whereNotExists(function ($locked) {
                $locked->selectRaw('1')
                    ->from('role_capabilities')
                    ->whereColumn('role_capabilities.role_id', 'branch_role_capabilities.role_id')
                    ->whereColumn('role_capabilities.capability', 'branch_role_capabilities.capability')
                    ->where('role_capabilities.is_locked', true);
            })
            ->pluck('capability')
            ->all();
    }
}
