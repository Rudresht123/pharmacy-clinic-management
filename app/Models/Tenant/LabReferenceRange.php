<?php

namespace App\Models\Tenant;

use App\Support\Deletion\ForbidsForceDelete;
use App\Support\Deletion\HasDeletionAudit;
use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * What normal looks like for one parameter, for one sex and age band.
 *
 * ENTERED BY QUALIFIED STAFF, NEVER SEEDED. A range is a clinical decision a
 * pathologist makes for their own analyser and population; the software holds
 * it and applies it, and invents none.
 *
 * The age band keeps its own unit — "0–30 days", "1–12 months", "18+ years" —
 * and is compared in that unit (`age_min` inclusive, `age_max` exclusive). A
 * band with no ages applies at any age.
 */
class LabReferenceRange extends Model
{
    use ForbidsForceDelete, HasDeletionAudit, RecordsHistory, SoftDeletes;

    public const ANY = 'any';

    public const MALE = 'male';

    public const FEMALE = 'female';

    public const SEXES = [self::ANY, self::MALE, self::FEMALE];

    public const DAYS = 'days';

    public const MONTHS = 'months';

    public const YEARS = 'years';

    public const AGE_UNITS = [self::DAYS, self::MONTHS, self::YEARS];

    /** Average lengths, used only to compare bands written in different units. */
    public const DAYS_IN = [self::DAYS => 1, self::MONTHS => 30.4375, self::YEARS => 365.25];

    protected $connection = 'organization';

    protected $table = 'lab_reference_ranges';

    protected $fillable = [
        'lab_test_parameter_id',
        'sex',
        'age_min',
        'age_max',
        'age_unit',
        'low',
        'high',
        'critical_low',
        'critical_high',
        'normal_text',
        'display_text',
        'notes',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'lab_test_parameter_id' => 'integer',
            'age_min' => 'integer',
            'age_max' => 'integer',
            'position' => 'integer',
        ];
    }

    public function parameter(): BelongsTo
    {
        return $this->belongsTo(LabTestParameter::class, 'lab_test_parameter_id')->withTrashed();
    }

    public function hasAgeBand(): bool
    {
        return $this->age_min !== null || $this->age_max !== null;
    }

    /**
     * The band as days, for comparing bands written in different units.
     *
     * @return array{0: float, 1: float}
     */
    public function bandInDays(): array
    {
        $per = self::DAYS_IN[$this->age_unit ?? self::YEARS];

        return [
            $this->age_min === null ? 0.0 : $this->age_min * $per,
            $this->age_max === null ? INF : $this->age_max * $per,
        ];
    }

    /** "Female, 18–60 years" — who a range is for, in a sentence. */
    public function appliesToText(): string
    {
        $who = match ($this->sex) {
            self::MALE => 'Male',
            self::FEMALE => 'Female',
            default => 'Anyone',
        };

        if (! $this->hasAgeBand()) {
            return $who.', any age';
        }

        $unit = $this->age_unit;

        $band = match (true) {
            $this->age_min === null => "under {$this->age_max} {$unit}",
            $this->age_max === null => "{$this->age_min}+ {$unit}",
            default => "{$this->age_min}–{$this->age_max} {$unit}",
        };

        return "{$who}, {$band}";
    }

    /**
     * How the range prints: its own words if it has them, else the numbers.
     * "13.0 – 17.0", "< 200", "> 40", or the normal answer for a text result.
     */
    public function printed(?int $decimals = null): ?string
    {
        if ($this->display_text) {
            return $this->display_text;
        }

        $low = self::number($this->low, $decimals);
        $high = self::number($this->high, $decimals);

        return match (true) {
            $low !== null && $high !== null => "{$low} – {$high}",
            $high !== null => "< {$high}",
            $low !== null => "> {$low}",
            default => $this->normal_text,
        };
    }

    /** A stored decimal as a person writes it: no trailing zeros unless the parameter asks for them. */
    public static function number(mixed $value, ?int $decimals = null): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($decimals !== null) {
            return number_format((float) $value, $decimals, '.', '');
        }

        $text = rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.');

        return $text === '-0' ? '0' : $text;
    }

    protected function historyLabel(): ?string
    {
        return $this->appliesToText();
    }
}
