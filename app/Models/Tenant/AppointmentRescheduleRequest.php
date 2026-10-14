<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A patient asking to move a booking, and the desk's answer.
 *
 *   pending ─┬─ desk says yes ─→ approved   (the visit moves)
 *            ├─ desk says no ──→ rejected   (the visit stays)
 *            └─ patient drops it → withdrawn
 *
 * One pending request per visit — a partial unique index. Asking again while
 * one is open replaces it rather than queueing a second.
 */
class AppointmentRescheduleRequest extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const WITHDRAWN = 'withdrawn';

    public const STATUSES = [self::PENDING, self::APPROVED, self::REJECTED, self::WITHDRAWN];

    protected $connection = 'organization';

    protected $fillable = [
        'appointment_id',
        'customer_id',
        'doctor_id',
        'location_id',
        'from_date',
        'from_slot_at',
        'requested_date',
        'requested_slot_at',
        'requested_location_id',
        'reason',
        'status',
        'approved_date',
        'approved_slot_at',
        'decided_by',
        'decided_at',
        'decision_note',
    ];

    protected function casts(): array
    {
        return [
            'from_date' => 'date',
            'requested_date' => 'date',
            'approved_date' => 'date',
            'decided_at' => 'datetime',
        ];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
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

    public function requestedLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'requested_location_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    /** @param  Builder<AppointmentRescheduleRequest>  $query */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::PENDING);
    }

    /** "10:30" from Postgres' "10:30:00". */
    public static function hhmm(mixed $time): ?string
    {
        return $time === null ? null : substr((string) $time, 0, 5);
    }
}
