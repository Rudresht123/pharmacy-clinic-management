<?php

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per lifecycle change — Build Spec §4, §9.
 *
 * Append-only in practice: rows are written when the status moves and never
 * edited, so the Overview tab can answer "when was this suspended, by whom,
 * and why" without reading the platform audit log.
 */
class OrganizationStatusHistory extends Model
{
    protected $table = 'organization_status_history';

    protected $fillable = [
        'organization_id',
        'from_status',
        'to_status',
        'reason',
        'changed_by',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Null when a scheduled command made the change rather than a person. */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'changed_by');
    }
}
