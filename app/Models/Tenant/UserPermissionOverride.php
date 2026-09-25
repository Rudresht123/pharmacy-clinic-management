<?php

namespace App\Models\Tenant;

use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One capability taken off one person.
 *
 * The exception that lets a role stay the unit. Without it, "the receptionist
 * role but this one cannot refund" meant cloning the role, and three
 * receptionists with three small differences meant three roles that then
 * drifted apart until nobody could say what a receptionist was.
 *
 * DENY ONLY today — see the migration for why. `effect` exists as a column so
 * adding `allow` later needs no migration of the rows written now, but nothing
 * writes anything else, and Permission reads denies alone.
 *
 * A null `location_id` means everywhere. Somebody who works at two branches
 * and may not refund at either is one row, not two.
 */
class UserPermissionOverride extends Model
{
    use RecordsHistory;

    public const DENY = 'deny';

    protected $connection = 'organization';

    protected $fillable = [
        'user_id',
        'location_id',
        'capability',
        'effect',
        'created_by',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** Reads in the activity log as what it is, rather than as two ids. */
    public function historyLabel(): string
    {
        $person = $this->user?->name ?? "user #{$this->user_id}";
        $where = $this->location?->name ?? 'every branch';

        return "{$this->capability} denied to {$person} at {$where}";
    }
}
