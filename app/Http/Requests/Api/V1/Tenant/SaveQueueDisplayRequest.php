<?php

namespace App\Http\Requests\Api\V1\Tenant;

use App\Models\Tenant\Department;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Location;
use App\Models\Tenant\QueueDisplay;
use App\Services\Queue\QueueBoard;
use App\Services\Tenancy\TenantBranchAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * What a television shows, and how it looks.
 *
 * What makes a display complete depends on its type: a room's screen names
 * exactly one doctor, a department's names a department. The doctors must work
 * at the branch, or a card would sit there empty forever with no explanation.
 */
class SaveQueueDisplayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'display_name' => ['required', 'string', 'max:120'],
            'display_type' => ['required', Rule::in(QueueDisplay::TYPES)],
            'location_id' => ['required', 'integer', Rule::exists(Location::class, 'id')->whereNull('deleted_at')],

            'department_id' => [
                Rule::requiredIf(fn () => $this->input('display_type') === QueueDisplay::DEPARTMENT),
                'nullable', 'integer',
                Rule::exists(Department::class, 'id')->whereNull('deleted_at'),
            ],

            'doctor_ids' => ['nullable', 'array', 'max:60'],
            'doctor_ids.*' => ['integer', Rule::exists(Doctor::class, 'id')->whereNull('deleted_at')],

            'layout' => ['nullable', Rule::in(QueueDisplay::LAYOUTS)],
            'theme' => ['nullable', Rule::in(QueueDisplay::THEMES)],
            'audio_enabled' => ['nullable', 'boolean'],
            'audio_languages' => ['nullable', 'array'],
            'audio_languages.*' => [Rule::in(QueueDisplay::LANGUAGES)],
            'is_active' => ['nullable', 'boolean'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $locationId = (int) $this->input('location_id');

            if (! app(TenantBranchAccess::class)->currentCanUse($locationId)) {
                $validator->errors()->add('location_id', 'You can only set up displays at a branch you work at.');

                return;
            }

            $doctorIds = array_map('intval', $this->input('doctor_ids') ?? []);

            if ($this->input('display_type') === QueueDisplay::DOCTOR && count(array_unique($doctorIds)) !== 1) {
                $validator->errors()->add('doctor_ids', 'A room display shows exactly one doctor. Choose who.');

                return;
            }

            if ($doctorIds === []) {
                return;
            }

            $posted = app(QueueBoard::class)->postedAt($locationId)->pluck('id')->map(fn ($id) => (int) $id)->all();

            if (array_diff($doctorIds, $posted) !== []) {
                $validator->errors()->add('doctor_ids', 'Every doctor on a display has to work at its branch.');
            }
        });
    }

    /** @return array<string, mixed> */
    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated($key, $default);

        if ($key !== null || ! is_array($data)) {
            return $data;
        }

        // Only what is sent changes on an edit; a new display gets the defaults.
        if ($this->isMethod('post')) {
            $data['layout'] ??= 'grid';
            $data['theme'] ??= 'dark';
            $data['audio_enabled'] ??= false;
            $data['is_active'] ??= true;
            $data['doctor_ids'] ??= [];
        }

        return $data;
    }
}
