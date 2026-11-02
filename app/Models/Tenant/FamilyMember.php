<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Somebody a patient looks after from the app.
 *
 * Only the link: `member` is an ordinary patient record of the clinic's, and
 * `account` is the patient whose login books for them. See the migration.
 */
class FamilyMember extends Model
{
    public const SPOUSE = 'spouse';

    public const CHILD = 'child';

    public const PARENT = 'parent';

    public const SIBLING = 'sibling';

    public const OTHER = 'other';

    /** Mirrors the database CHECK. */
    public const RELATIONS = [self::SPOUSE, self::CHILD, self::PARENT, self::SIBLING, self::OTHER];

    protected $connection = 'organization';

    protected $table = 'family_members';

    protected $fillable = [
        'customer_id',
        'member_id',
        'relation',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'member_id');
    }
}
