<?php

namespace App\Models\Tenant;

use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Somebody intending to see a doctor.
 *
 * An intent, not an outcome. What happened in the room is a visit, arriving
 * in Phase 3; nothing clinical belongs here.
 */
class Appointment extends Model
{
    use RecordsHistory, SoftDeletes;

    /** Booked ahead, with a promised time. */
    public const BOOKED = 'booked';

    /** Turned up without one. */
    public const WALK_IN = 'walk_in';

    public const TYPES = [self::BOOKED, self::WALK_IN];

    /*
     * The states, in the order they happen.
     *
     * `booked` is the only one a walk-in skips: they are checked in the
     * moment they are recorded, because they are standing there.
     */
    public const STATUS_BOOKED = 'booked';

    public const STATUS_CHECKED_IN = 'checked_in';

    public const STATUS_IN_CONSULTATION = 'in_consultation';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_NO_SHOW = 'no_show';

    public const STATUSES = [
        self::STATUS_BOOKED,
        self::STATUS_CHECKED_IN,
        self::STATUS_IN_CONSULTATION,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
        self::STATUS_NO_SHOW,
    ];

    /**
     * Which states each one may move to.
     *
     * Written down rather than left to whichever controller happens to be
     * called: without it, a completed consultation can be checked in again
     * and the day's figures stop meaning anything.
     */
    public const TRANSITIONS = [
        self::STATUS_BOOKED => [self::STATUS_CHECKED_IN, self::STATUS_CANCELLED, self::STATUS_NO_SHOW],
        self::STATUS_CHECKED_IN => [self::STATUS_IN_CONSULTATION, self::STATUS_CANCELLED],
        self::STATUS_IN_CONSULTATION => [self::STATUS_COMPLETED, self::STATUS_CANCELLED],
        self::STATUS_COMPLETED => [],
        self::STATUS_CANCELLED => [],
        self::STATUS_NO_SHOW => [],
    ];

    protected $connection = 'organization';

    protected $fillable = [
        'customer_id',
        'doctor_id',
        'location_id',
        'doctor_schedule_id',
        'appointment_date',
        'type',
        'status',
        'slot_at',
        'token_no',
        'checked_in_at',
        'started_at',
        'completed_at',
        'cancellation_reason',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'appointment_date' => 'date',
            'token_no' => 'integer',
            'checked_in_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** The sitting this came out of, while that sitting still exists. */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(DoctorSchedule::class, 'doctor_schedule_id');
    }

    public function canMoveTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /** Still to be seen — what the queue shows. */
    public function scopeWaiting(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_CHECKED_IN, self::STATUS_IN_CONSULTATION]);
    }

    /** Cancelled bookings free their slot; nothing else does. */
    public function scopeHoldingASlot(Builder $query): Builder
    {
        return $query->whereNotNull('slot_at')->where('status', '<>', self::STATUS_CANCELLED);
    }

    /**
     * "Asha Rane · 14 Sep 2026 · 10:30", for the audit log.
     *
     * Named so a cancelled appointment still says whose it was long after
     * the row is gone.
     */
    protected function historyLabel(): ?string
    {
        $who = $this->customer?->name ?? "Patient #{$this->customer_id}";
        $when = $this->appointment_date?->format('d M Y') ?? '';
        $at = $this->slot_at ? ' · '.substr($this->slot_at, 0, 5) : '';

        return trim("{$who} · {$when}{$at}");
    }
}
