<?php

namespace App\Models\Tenant;

use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * How one doctor runs their queue AT ONE BRANCH.
 *
 * Per posting rather than on the doctor, because the room is a fact about the
 * building: the same doctor is Room 01 at Noida and Room 7 at Gurgaon. Made
 * the first time anybody needs one (QueueSettings::forDoctor), so a doctor
 * nobody has configured still gets a working queue with sensible defaults and a
 * letter of their own.
 *
 * The row doubles as the doctor's queue lock: "Call next" holds it while it
 * decides who is next, so two clicks for the same doctor take turns and two
 * doctors never wait on each other.
 */
class DoctorQueueSetting extends Model
{
    use RecordsHistory;

    public const DEFAULT_AVERAGE_MINUTES = 10;

    protected $connection = 'organization';

    protected $fillable = [
        'location_id',
        'doctor_id',
        'room_no',
        'token_prefix',
        'average_consultation_minutes',
        'auto_call_enabled',
        'allow_priority',
        'queue_enabled',
    ];

    protected $attributes = [
        'average_consultation_minutes' => self::DEFAULT_AVERAGE_MINUTES,
        'auto_call_enabled' => false,
        'allow_priority' => true,
        'queue_enabled' => true,
    ];

    protected function casts(): array
    {
        return [
            'location_id' => 'integer',
            'doctor_id' => 'integer',
            'average_consultation_minutes' => 'integer',
            'auto_call_enabled' => 'boolean',
            'allow_priority' => 'boolean',
            'queue_enabled' => 'boolean',
            'paused_at' => 'datetime',
            'pause_until' => 'datetime',
            'paused_by' => 'integer',
        ];
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class)->withTrashed();
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * Paused right now.
     *
     * Only today's stamp counts, and a pause with an end stops by itself at
     * that end — somebody back from lunch should not also have to remember to
     * tell the software.
     */
    public function isPaused(?Carbon $now = null): bool
    {
        if ($this->paused_at === null) {
            return false;
        }

        $now ??= now();

        if (! $this->paused_at->isSameDay($now)) {
            return false;
        }

        return $this->pause_until === null || $this->pause_until->isAfter($now);
    }

    protected function historyLabel(): ?string
    {
        return 'Queue settings · doctor #'.$this->doctor_id;
    }
}
