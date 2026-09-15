<?php

namespace App\Models\Tenant;

use App\Support\Deletion\ForbidsForceDelete;
use App\Support\Deletion\HasDeletionAudit;
use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One line of a prescription.
 *
 * A catalogue medicine, or an unlisted one (no `medicine_id`) — free text the
 * doctor typed, which can be read and printed but not dispensed until it is
 * mapped. The snapshot columns are written once, when the medicine is
 * chosen, so the line reads the same after the catalogue changes.
 *
 * `dispensed_quantity`, `over_dispense_*` and `status` are dispensing's to
 * write (Phase 5), never a request's.
 */
class PrescriptionItem extends Model
{
    use ForbidsForceDelete, HasDeletionAudit, RecordsHistory, SoftDeletes;

    public const PENDING = 'pending';

    public const PARTIALLY_DISPENSED = 'partially_dispensed';

    public const DISPENSED = 'dispensed';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::PENDING, self::PARTIALLY_DISPENSED, self::DISPENSED, self::CANCELLED];

    /** Every value the database CHECK permits, in the order a doctor reads them. */
    public const FREQUENCIES = ['od', 'bd', 'tds', 'qid', 'hs', 'sos', 'stat', 'weekly', 'custom'];

    public const FOOD_TIMINGS = ['before_food', 'after_food', 'with_food', 'empty_stomach', 'any'];

    public const DURATION_UNITS = ['days', 'weeks', 'months', 'continuous'];

    public const FREQUENCY_LABELS = [
        'od' => 'Once a day',
        'bd' => 'Twice a day',
        'tds' => 'Three times a day',
        'qid' => 'Four times a day',
        'hs' => 'At bedtime',
        'sos' => 'When needed',
        'stat' => 'Once, straight away',
        'weekly' => 'Once a week',
        'custom' => 'As directed',
    ];

    public const FOOD_LABELS = [
        'before_food' => 'Before food',
        'after_food' => 'After food',
        'with_food' => 'With food',
        'empty_stomach' => 'On an empty stomach',
    ];

    /**
     * Doses a day. Absent for `sos` and `custom`, which cannot be counted
     * ahead, and `stat`, which is one dose in all.
     */
    private const PER_DAY = ['od' => 1, 'bd' => 2, 'tds' => 3, 'qid' => 4, 'hs' => 1, 'weekly' => 1 / 7];

    private const DAYS_IN = ['days' => 1, 'weeks' => 7, 'months' => 30];

    /** The pattern slots, in the order they are written: 1–0–0–1. */
    public const SLOTS = ['morning', 'afternoon', 'evening', 'night'];

    protected $connection = 'organization';

    protected $fillable = [
        'prescription_id',
        'medicine_id',
        'medicine_name_snapshot',
        'generic_name_snapshot',
        'strength_snapshot',
        'dosage_form_snapshot',
        'dose_amount',
        'dose_unit',
        'morning',
        'afternoon',
        'evening',
        'night',
        'frequency',
        'food_timing',
        'duration',
        'duration_unit',
        'route',
        'prescribed_quantity',
        'instructions',
        'legacy_line',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'prescription_id' => 'integer',
            'medicine_id' => 'integer',
            'dose_amount' => 'decimal:2',
            'morning' => 'decimal:2',
            'afternoon' => 'decimal:2',
            'evening' => 'decimal:2',
            'night' => 'decimal:2',
            'duration' => 'integer',
            'prescribed_quantity' => 'integer',
            'dispensed_quantity' => 'integer',
            'over_dispense_allowance' => 'integer',
            'legacy_line' => 'array',
            'sort_order' => 'integer',
        ];
    }

    /** @return list<string> */
    protected function historyExcept(): array
    {
        return ['instructions', 'legacy_line'];
    }

    protected function historyLabel(): ?string
    {
        return mb_substr((string) $this->medicine_name_snapshot, 0, 191);
    }

    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class)->withTrashed();
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class)->withTrashed();
    }

    public function isUnlisted(): bool
    {
        return $this->medicine_id === null;
    }

    /**
     * The frequency a written pattern implies: 1–0–1 is twice a day.
     *
     * @param  array<string, mixed>  $line
     */
    public static function frequencyFromPattern(array $line): ?string
    {
        $doses = count(array_filter(self::SLOTS, fn (string $slot) => (float) ($line[$slot] ?? 0) > 0));

        return [1 => 'od', 2 => 'bd', 3 => 'tds', 4 => 'qid'][$doses] ?? null;
    }

    /**
     * How many base units the line adds up to — dose × doses a day × days.
     *
     * Only when the dose is counted in the medicine's own base unit ("1
     * tablet" of a tablet): 5 ml of a syrup sold by the bottle is not a count
     * of bottles, and a guess there would be worse than asking. Null when it
     * cannot be worked out; the doctor then says how many.
     *
     * @param  array<string, mixed>  $line
     */
    public static function suggestQuantity(array $line, ?string $baseUnit): ?int
    {
        $unit = strtolower(trim((string) ($line['dose_unit'] ?? '')));

        if ($unit !== '' && rtrim($unit, 's') !== strtolower((string) $baseUnit)) {
            return null;
        }

        $dose = (float) ($line['dose_amount'] ?? 0) ?: 1.0;
        $frequency = $line['frequency'] ?? null;

        if ($frequency === 'stat') {
            return (int) ceil($dose);
        }

        $days = self::DAYS_IN[$line['duration_unit'] ?? ''] ?? null;

        if ($days === null || empty($line['duration'])) {
            return null;
        }

        $days *= (int) $line['duration'];

        // A written pattern is itself the dose at each time of day.
        $pattern = array_sum(array_map(fn (string $slot) => (float) ($line[$slot] ?? 0), self::SLOTS));

        $perDay = $pattern > 0 ? $pattern : (isset(self::PER_DAY[$frequency]) ? $dose * self::PER_DAY[$frequency] : null);

        return $perDay === null ? null : max(1, (int) ceil($perDay * $days));
    }

    /**
     * The line in the consultation's old shape.
     *
     * A line moved across from the JSON returns its original text exactly;
     * a structured one is written out the way a doctor would say it.
     *
     * @return array{drug: string, dose: ?string, frequency: ?string, duration: ?string, notes: ?string}
     */
    public function asConsultationLine(): array
    {
        if (is_array($this->legacy_line)) {
            return [
                'drug' => (string) ($this->legacy_line['drug'] ?? $this->medicine_name_snapshot),
                'dose' => $this->legacy_line['dose'] ?? null,
                'frequency' => $this->legacy_line['frequency'] ?? null,
                'duration' => $this->legacy_line['duration'] ?? null,
                'notes' => $this->legacy_line['notes'] ?? null,
            ];
        }

        return [
            'drug' => (string) $this->medicine_name_snapshot,
            'dose' => $this->dose_amount !== null
                ? trim(self::number($this->dose_amount).' '.($this->dose_unit ?? ''))
                : null,
            'frequency' => $this->frequencyText(),
            'duration' => $this->durationText(),
            'notes' => implode(' · ', array_filter([
                self::FOOD_LABELS[$this->food_timing] ?? null,
                $this->instructions,
            ])) ?: null,
        ];
    }

    /** "1-0-0-1" when a pattern is written, otherwise the frequency in words. */
    private function frequencyText(): ?string
    {
        $slots = array_map(fn (string $slot) => $this->{$slot}, self::SLOTS);

        if (array_filter($slots, fn ($value) => $value !== null) !== []) {
            return implode('-', array_map(fn ($value) => self::number($value ?? 0), $slots));
        }

        return self::FREQUENCY_LABELS[$this->frequency] ?? null;
    }

    private function durationText(): ?string
    {
        if ($this->duration_unit === 'continuous') {
            return 'Continuous';
        }

        if (! $this->duration || ! $this->duration_unit) {
            return null;
        }

        $unit = $this->duration === 1 ? rtrim($this->duration_unit, 's') : $this->duration_unit;

        return "{$this->duration} {$unit}";
    }

    /** 1.00 → "1", 0.50 → "0.5". */
    private static function number(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }
}
