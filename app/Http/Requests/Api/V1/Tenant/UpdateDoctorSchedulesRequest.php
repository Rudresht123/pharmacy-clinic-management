<?php

namespace App\Http\Requests\Api\V1\Tenant;

use App\Models\Tenant\Location;
use App\Support\Opd\Weekday;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A doctor's whole week, submitted at once.
 *
 * A full replacement rather than a row at a time, for a reason that is not
 * merely convenience: **overlaps cannot be validated one row at a time.**
 * Whether 10:00–13:00 clashes with anything depends on every other sitting
 * that doctor has that day, so the server has to see the week to answer.
 *
 * It also matches how the screen works — a week is edited, then saved — and
 * a schedule has no identity anybody refers to on its own.
 */
class UpdateDoctorSchedulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'schedules' => ['present', 'array'],

            /*
             * The model class, not the string 'locations' — a string table
             * name resolves against the master connection, which has no such
             * table, and blows up rather than failing validation.
             */
            'schedules.*.location_id' => [
                'required', 'integer',
                Rule::exists(Location::class, 'id')->whereNull('deleted_at'),
            ],

            'schedules.*.name' => ['nullable', 'string', 'max:60'],

            // Monday 0 … Sunday 6 — see App\Support\Opd\Weekday.
            'schedules.*.weekday' => ['required', 'integer', Rule::in(Weekday::all())],

            'schedules.*.starts_at' => ['required', 'date_format:H:i'],
            'schedules.*.ends_at' => ['required', 'date_format:H:i', 'after:schedules.*.starts_at'],

            'schedules.*.slot_minutes' => ['required', 'integer', 'min:1', 'max:240'],
            'schedules.*.max_walkins' => ['nullable', 'integer', 'min:0', 'max:999'],
            'schedules.*.is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * No doctor is in two places at once.
     *
     * Checked across the whole submitted week, and deliberately **ignoring
     * the branch**: a sitting in Gurgaon from 10:00 and one in Delhi from
     * 10:30 are not two valid rows, they are a person who cannot exist. Left
     * unchecked, the booking screen would generate two overlapping sets of
     * slots and double-book a real doctor.
     *
     * An inactive sitting is skipped — it produces no slots, so it cannot
     * clash with anything.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $rows = collect($this->input('schedules', []))
                ->filter(fn ($row) => ($row['is_active'] ?? true))
                ->values();

            foreach ($rows as $i => $row) {
                foreach ($rows as $j => $other) {
                    if ($j <= $i || ($row['weekday'] ?? null) !== ($other['weekday'] ?? null)) {
                        continue;
                    }

                    // Half-open comparison: a sitting ending at 13:00 and one
                    // starting at 13:00 are back to back, not overlapping.
                    $clashes = ($row['starts_at'] ?? '') < ($other['ends_at'] ?? '')
                        && ($other['starts_at'] ?? '') < ($row['ends_at'] ?? '');

                    if (! $clashes) {
                        continue;
                    }

                    $day = Weekday::names()[$row['weekday']] ?? 'that day';

                    $validator->errors()->add(
                        "schedules.{$j}.starts_at",
                        "This overlaps another {$day} sitting ("
                        .$row['starts_at'].'–'.$row['ends_at'].
                        '). A doctor cannot be in two places at once.'
                    );
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'schedules.*.ends_at.after' => 'A sitting has to end after it starts.',
            'schedules.*.location_id.exists' => 'That branch does not exist.',
        ];
    }
}
