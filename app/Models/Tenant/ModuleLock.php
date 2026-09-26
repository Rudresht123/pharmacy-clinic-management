<?php

namespace App\Models\Tenant;

use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Model;

/**
 * A module the organization has made compulsory at every branch.
 *
 * Sparse: a row means locked, and there is nothing to read for a module
 * nobody has locked.
 *
 * Records its own history, unlike the other sparse tables around it. This one
 * is written one row at a time by a deliberate act — "billing is not optional
 * any more" — rather than replaced wholesale as part of a larger save, so the
 * row IS the event and there is no owning record whose log would tell the
 * story better.
 */
class ModuleLock extends Model
{
    use RecordsHistory;

    /**
     * Every tenant table lives in a different physical database, filled in
     * at runtime by TenantConnectionService — this model must never be
     * queried against whatever the default connection happens to be.
     */
    protected $connection = 'organization';

    protected $table = 'module_locks';

    protected $fillable = [
        'module_key',
        'created_by',
    ];

    /** @return list<string> */
    public static function lockedKeys(): array
    {
        return static::query()->pluck('module_key')->all();
    }

    /** Names the module in the activity log rather than showing an id. */
    public function historyLabel(): string
    {
        return $this->module_key;
    }
}
