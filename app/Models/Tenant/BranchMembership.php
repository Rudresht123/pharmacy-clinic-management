<?php

namespace App\Models\Tenant;

use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's place at one branch, and what they may do there.
 *
 * A model rather than a bare pivot, because the relationship carries facts of
 * its own — the role held there, and whether it is the branch the app opens
 * on. It is also a thing that happens: somebody joins a branch, moves, leaves.
 * A row with a history worth reading is not a link table.
 *
 * The role is per membership, which is the whole point. "Receptionist at
 * Lucknow, Branch Manager at Delhi" is two rows, and the Lucknow role grants
 * nothing at Delhi.
 *
 * Not soft-deleted: a membership that ended is a row that was removed, and
 * RecordsHistory keeps the record of who removed it. Soft-deleting would leave
 * rows that look like membership to any query that forgot the scope.
 */
class BranchMembership extends Model
{
    use RecordsHistory;

    /**
     * Every tenant table lives in a different physical database, filled in
     * at runtime by TenantConnectionService — this model must never be
     * queried against whatever the default connection happens to be.
     */
    protected $connection = 'organization';

    protected $table = 'branch_users';

    protected $fillable = [
        'user_id',
        'location_id',
        'role_id',
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * What this membership grants, here.
     *
     * Empty for a membership with no role — somebody who belongs to a branch
     * but has not been given anything to do there yet, which is a real state
     * and not an error.
     *
     * @return list<string>
     */
    public function capabilityKeys(): array
    {
        return $this->role?->capabilityKeys() ?? [];
    }

    /** Names the row in the activity log rather than showing two ids. */
    public function historyLabel(): string
    {
        $person = $this->user?->name ?? "user #{$this->user_id}";
        $branch = $this->location?->name ?? "branch #{$this->location_id}";

        return "{$person} at {$branch}";
    }
}
