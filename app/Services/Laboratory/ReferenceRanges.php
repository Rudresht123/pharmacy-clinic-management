<?php

namespace App\Services\Laboratory;

use App\Models\Tenant\LabReferenceRange;
use App\Models\Tenant\LabTestParameter;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * What normal looks like for a parameter, and which range a patient falls in.
 *
 * ONE RANGE PER PERSON, BY CONSTRUCTION. Within one sex, two ranges may not
 * cover the same age, so there is never a choice to make between them. A range
 * for `any` sex is the fallback underneath: a woman is matched against the
 * female ranges first, and only if none covers her age against the `any` ones.
 * Somebody recorded as `other`, or with no sex at all, is matched against `any`
 * only. A patient with no date of birth matches only a range with no age band.
 *
 * No match means no range, and no flag: the software never guesses what normal
 * is. Ranges are written only under `laboratory.ranges_manage`.
 */
class ReferenceRanges
{
    /**
     * Replace a parameter's ranges with the given set, in order.
     *
     * Rows with an `id` are that range, changed; rows without are new; ranges
     * left out are removed with a reason, so the history keeps what they said.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return Collection<int, LabReferenceRange>
     */
    public function replace(LabTestParameter $parameter, array $rows): Collection
    {
        $rows = array_values($rows);

        $this->assertFitsTheParameter($parameter, $rows);
        $this->assertNoOverlap($rows);

        return $parameter->getConnection()->transaction(function () use ($parameter, $rows) {
            $current = $parameter->ranges()->get()->keyBy('id');
            $kept = [];

            foreach ($rows as $index => $row) {
                $range = ! empty($row['id'])
                    ? ($current->get((int) $row['id']) ?? throw ValidationException::withMessages([
                        "ranges.{$index}.id" => ['That range is not on this parameter. Reload and try again.'],
                    ]))
                    : new LabReferenceRange(['lab_test_parameter_id' => $parameter->id]);

                $range->fill([
                    'sex' => $row['sex'] ?? LabReferenceRange::ANY,
                    'age_min' => $row['age_min'] ?? null,
                    'age_max' => $row['age_max'] ?? null,
                    'age_unit' => isset($row['age_min']) || isset($row['age_max']) ? ($row['age_unit'] ?? null) : null,
                    'low' => $row['low'] ?? null,
                    'high' => $row['high'] ?? null,
                    'critical_low' => $row['critical_low'] ?? null,
                    'critical_high' => $row['critical_high'] ?? null,
                    'normal_text' => $row['normal_text'] ?? null,
                    'display_text' => $row['display_text'] ?? null,
                    'notes' => $row['notes'] ?? null,
                    'position' => $index,
                ]);

                if (! $range->exists || $range->isDirty()) {
                    $range->save();
                }

                $kept[] = $range->id;
            }

            foreach ($current->except($kept) as $gone) {
                $gone->deleteWithReason('Removed from the parameter\'s ranges');
            }

            return $parameter->ranges()->get();
        });
    }

    /**
     * The range that applies to this patient, or null.
     *
     * @param  iterable<LabReferenceRange>|null  $ranges  already loaded; read if not
     */
    public function resolve(
        LabTestParameter $parameter,
        ?string $sex,
        ?CarbonInterface $dateOfBirth,
        ?CarbonInterface $at = null,
        ?iterable $ranges = null,
    ): ?LabReferenceRange {
        $ranges = collect($ranges ?? $parameter->ranges()->get());
        $at ??= now();

        $fits = fn (LabReferenceRange $range) => $this->coversAge($range, $dateOfBirth, $at);

        if (in_array($sex, [LabReferenceRange::MALE, LabReferenceRange::FEMALE], true)) {
            $own = $ranges->first(fn (LabReferenceRange $range) => $range->sex === $sex && $fits($range));

            if ($own) {
                return $own;
            }
        }

        return $ranges->first(fn (LabReferenceRange $range) => $range->sex === LabReferenceRange::ANY && $fits($range));
    }

    /** The patient's completed age in a band's own unit: 18 on their eighteenth birthday. */
    public static function ageIn(string $unit, CarbonInterface $dateOfBirth, CarbonInterface $at): int
    {
        $from = $dateOfBirth->copy()->startOfDay();
        $to = $at->copy()->startOfDay();

        return (int) match ($unit) {
            LabReferenceRange::DAYS => $from->diffInDays($to),
            LabReferenceRange::MONTHS => $from->diffInMonths($to),
            default => $from->diffInYears($to),
        };
    }

    private function coversAge(LabReferenceRange $range, ?CarbonInterface $dateOfBirth, CarbonInterface $at): bool
    {
        if (! $range->hasAgeBand()) {
            return true;
        }

        if ($dateOfBirth === null || $dateOfBirth->greaterThan($at)) {
            return false;
        }

        $age = self::ageIn($range->age_unit ?? LabReferenceRange::YEARS, $dateOfBirth, $at);

        return ($range->age_min === null || $age >= $range->age_min)
            && ($range->age_max === null || $age < $range->age_max);
    }

    /**
     * Numbers only where the answer is a number; words where it is not.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function assertFitsTheParameter(LabTestParameter $parameter, array $rows): void
    {
        $errors = [];

        foreach ($rows as $index => $row) {
            $at = "ranges.{$index}";
            $limits = ['low', 'high', 'critical_low', 'critical_high'];
            $has = fn (string $field) => isset($row[$field]) && $row[$field] !== '';

            if ($parameter->isNumeric()) {
                if (! $has('low') && ! $has('high') && ! $has('display_text')) {
                    $errors["{$at}.low"][] = 'Give a low or a high limit (or how the range prints).';
                }

                $order = [
                    ['critical_low', 'low', 'The critical low must be at or below the low limit.'],
                    ['low', 'high', 'The low limit must be at or below the high limit.'],
                    ['high', 'critical_high', 'The critical high must be at or above the high limit.'],
                    ['critical_low', 'critical_high', 'The critical low must be below the critical high.'],
                ];

                foreach ($order as [$below, $above, $message]) {
                    if ($has($below) && $has($above) && (float) $row[$below] > (float) $row[$above]) {
                        $errors["{$at}.{$below}"][] = $message;
                    }
                }
            } else {
                foreach ($limits as $field) {
                    if ($has($field)) {
                        $errors["{$at}.{$field}"][] = "{$parameter->name} is not answered with a number, so it has no numeric limits.";
                    }
                }

                if (! $has('normal_text') && ! $has('display_text')) {
                    $errors["{$at}.normal_text"][] = 'Say what a normal answer is, e.g. Negative.';
                }
            }

            if (($has('age_min') || $has('age_max')) && ! $has('age_unit')) {
                $errors["{$at}.age_unit"][] = 'Say whether the ages are in days, months or years.';
            }

            if ($has('age_min') && $has('age_max') && (int) $row['age_min'] >= (int) $row['age_max']) {
                $errors["{$at}.age_max"][] = 'The band must end after it starts.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Within one sex, no two ranges may cover the same age.
     *
     * Bands in different units are compared in days, using average month and
     * year lengths; a band ends where the next begins, so 0–1 years and 1–18
     * years touch without overlapping.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function assertNoOverlap(array $rows): void
    {
        $bands = array_map(function (array $row) {
            $range = new LabReferenceRange([
                'sex' => $row['sex'] ?? LabReferenceRange::ANY,
                'age_min' => $row['age_min'] ?? null,
                'age_max' => $row['age_max'] ?? null,
                'age_unit' => $row['age_unit'] ?? LabReferenceRange::YEARS,
            ]);

            return [$range, $range->bandInDays()];
        }, $rows);

        foreach ($bands as $i => [$first, [$from1, $to1]]) {
            foreach (array_slice($bands, $i + 1, null, true) as $j => [$second, [$from2, $to2]]) {
                if ($first->sex !== $second->sex) {
                    continue;
                }

                $epsilon = 1e-6;

                if ($from1 < $to2 - $epsilon && $from2 < $to1 - $epsilon) {
                    throw ValidationException::withMessages([
                        "ranges.{$j}.age_min" => [sprintf(
                            'Ranges %d and %d both cover %s. Within one sex, each age must have one range.',
                            $i + 1,
                            $j + 1,
                            $first->appliesToText() === $second->appliesToText()
                                ? lcfirst($first->appliesToText())
                                : 'the same ages ('.$first->appliesToText().' / '.$second->appliesToText().')',
                        )],
                    ]);
                }
            }
        }
    }
}
