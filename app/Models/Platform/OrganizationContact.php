<?php

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * People to reach at a tenant — Build Spec §4.
 *
 * Separate from the organization's own owner_* columns, which seed the first
 * tenant user. These are support contacts: an accountant, a second pharmacist,
 * whoever answers when something breaks.
 */
class OrganizationContact extends Model
{
    protected $fillable = [
        'organization_id',
        'name',
        'role',
        'email',
        'phone',
        'is_primary',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
