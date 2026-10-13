<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One ring of the doctor's phone to the patient's, and what became of it.
 *
 *   ringing ─┬─ answered ──────────→ active ── either hangs up → ended
 *            ├─ patient says no ───→ declined
 *            ├─ doctor gives up ───→ cancelled
 *            └─ nobody answers ────→ missed
 *
 * Only `ringing` and `active` are live, and a visit holds one live call at
 * most (a partial unique index). The visit itself moves on through
 * VisitWorkflow when the patient answers — this row only records the call.
 */
class VideoCall extends Model
{
    public const RINGING = 'ringing';

    public const ACTIVE = 'active';

    public const ENDED = 'ended';

    public const MISSED = 'missed';

    public const DECLINED = 'declined';

    public const CANCELLED = 'cancelled';

    public const LIVE = [self::RINGING, self::ACTIVE];

    /** How long a ring lasts before it counts as missed. */
    public const RING_SECONDS = 60;

    public const BY_DOCTOR = 'doctor';

    public const BY_PATIENT = 'patient';

    public const BY_SYSTEM = 'system';

    protected $connection = 'organization';

    protected $fillable = [
        'appointment_id',
        'doctor_id',
        'customer_id',
        'doctor_user_id',
        'patient_user_id',
        'status',
        'answered_at',
        'ended_at',
        'ended_by',
    ];

    protected function casts(): array
    {
        return [
            'answered_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function signals(): HasMany
    {
        return $this->hasMany(VideoCallSignal::class);
    }

    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE, true);
    }

    public function isRinging(): bool
    {
        return $this->status === self::RINGING;
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /** When an unanswered ring stops counting as one. */
    public function ringExpiresAt(): ?Carbon
    {
        return $this->created_at?->copy()->addSeconds(self::RING_SECONDS);
    }

    /**
     * Still `ringing` on the row, but nobody can answer it any more.
     *
     * The doctor's phone gives up at the same moment and says so; this is for
     * the phone that never got the chance — switched off, or out of signal —
     * so a stale ring cannot be answered an hour later.
     */
    public function ringHasLapsed(): bool
    {
        return $this->isRinging() && $this->ringExpiresAt()?->isPast();
    }

    /** @param  Builder<VideoCall>  $query */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', self::LIVE);
    }
}
