<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing that happened to a doctor's queue, and who did it.
 *
 * Append-only, like MessageLog: an event that happened, not something anybody
 * maintains. `activity_logs` already keeps every column change; this is the
 * sentence a desk reads back — "A013 moved to Urgent by Priya: chest pain" —
 * and the one record of what a pause or a priority change was FOR.
 *
 * Written by QueueJournal and nothing else.
 */
class QueueEvent extends Model
{
    public const UPDATED_AT = null;

    public const CHECKED_IN = 'checked_in';

    public const CALLED = 'called';

    public const RECALLED = 'recalled';

    public const SERVING = 'serving';

    public const COMPLETED = 'completed';

    public const REOPENED = 'reopened';

    public const SKIPPED = 'skipped';

    public const REQUEUED = 'requeued';

    public const CANCELLED = 'cancelled';

    public const NO_SHOW = 'no_show';

    public const PRIORITY_CHANGED = 'priority_changed';

    public const REASSIGNED = 'reassigned';

    public const PAUSED = 'paused';

    public const RESUMED = 'resumed';

    public const SETTINGS_CHANGED = 'settings_changed';

    protected $connection = 'organization';

    protected $fillable = [
        'appointment_id',
        'location_id',
        'doctor_id',
        'queue_date',
        'event_type',
        'old_status',
        'new_status',
        'performed_by',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'appointment_id' => 'integer',
            'location_id' => 'integer',
            'doctor_id' => 'integer',
            'queue_date' => 'date',
            'performed_by' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class)->withTrashed();
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class)->withTrashed();
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
