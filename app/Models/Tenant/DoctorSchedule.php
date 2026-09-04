<?php

namespace App\Models\Tenant;

use App\Support\History\RecordsHistory;
use App\Support\Opd\Weekday;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One sitting: a doctor, at a branch, on a weekday, between two times.
 *
 * An availability window, not a set of appointments. `slot_minutes` says how
 * finely the window divides when somebody asks for free slots; it does not
 * mean any rows exist.
 */
class DoctorSchedule extends Model
{
    use RecordsHistory;

    protected $connection = 'organization';

    protected $fillable = [
        'doctor_id',
        'location_id',
        'name',
        'weekday',
        'starts_at',
        'ends_at',
        'slot_minutes',
        'max_walkins',
        'is_active',
        'effective_from',
        'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'slot_minutes' => 'integer',
            'max_walkins' => 'integer',
            'is_active' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    /**
     * Whether this sitting applies on a given date.
     *
     * Only the effective window — the weekday, and whatever exceptions that
     * date carries, are the availability service's business.
     */
    public function appliesOn(Carbon $date): bool
    {
        if ($this->effective_from && $date->lt($this->effective_from)) {
            return false;
        }

        return ! ($this->effective_to && $date->gt($this->effective_to));
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * "Mon · 10:00–13:00 · Main Street", or the sitting's own name if it has
     * one.
     *
     * The audit log stores this at write time, so a deleted sitting still
     * reads as something a person recognises rather than an id.
     */
    protected function historyLabel(): ?string
    {
        $when = Weekday::label($this->weekday ?? 0).' · '.
            $this->timeOnly($this->starts_at).'–'.$this->timeOnly($this->ends_at);

        $where = $this->location?->name;

        return trim(($this->name ? $this->name.' · ' : '').$when.($where ? ' · '.$where : ''));
    }

    /** Postgres returns a time column as "10:00:00"; nobody reads the seconds. */
    private function timeOnly(?string $value): string
    {
        return $value ? substr($value, 0, 5) : '';
    }
}
