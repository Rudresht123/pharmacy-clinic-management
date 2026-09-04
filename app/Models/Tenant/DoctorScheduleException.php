<?php

namespace App\Models\Tenant;

use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What happens on one date that the weekly pattern does not say.
 *
 * Leave, a holiday, hours moved for a morning, an extra Sunday clinic. The
 * weekly sittings are what usually happens; these are the departures from it.
 */
class DoctorScheduleException extends Model
{
    use RecordsHistory;

    /** Nothing happens — a sitting cancelled, or the whole day off. */
    public const UNAVAILABLE = 'unavailable';

    /** A named sitting runs at different times that day. */
    public const CHANGED_HOURS = 'changed_hours';

    /** A sitting that is not in the weekly pattern at all. */
    public const EXTRA_SESSION = 'extra_session';

    public const TYPES = [self::UNAVAILABLE, self::CHANGED_HOURS, self::EXTRA_SESSION];

    protected $connection = 'organization';

    protected $fillable = [
        'doctor_id',
        'doctor_schedule_id',
        'location_id',
        'date',
        'type',
        'starts_at',
        'ends_at',
        'slot_minutes',
        'max_walkins',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'slot_minutes' => 'integer',
            'max_walkins' => 'integer',
        ];
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(DoctorSchedule::class, 'doctor_schedule_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** Whether this takes the doctor off for the entire day. */
    public function isWholeDayOff(): bool
    {
        return $this->type === self::UNAVAILABLE && $this->doctor_schedule_id === null;
    }

    public function scopeOn(Builder $query, string $date): Builder
    {
        return $query->whereDate('date', $date);
    }

    /**
     * "Anjali Sharma away · 14 Sep 2026", for the audit log.
     *
     * A record with no name of its own borrows one, so a deleted exception
     * still reads as something a person recognises.
     */
    protected function historyLabel(): ?string
    {
        $what = match ($this->type) {
            self::UNAVAILABLE => 'away',
            self::CHANGED_HOURS => 'changed hours',
            self::EXTRA_SESSION => 'extra session',
            default => $this->type,
        };

        $when = $this->date?->format('d M Y') ?? '';

        return trim(($this->doctor?->name ?? 'Doctor')." {$what} · {$when}");
    }
}
