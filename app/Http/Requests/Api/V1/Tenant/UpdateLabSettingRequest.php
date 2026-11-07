<?php

namespace App\Http\Requests\Api\V1\Tenant;

use App\Models\Tenant\LabSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The laboratory's settings, saved. Every field optional: the screen sends
 * what it shows, and a partial save changes only what it names.
 */
class UpdateLabSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'approval_levels' => ['sometimes', 'integer', Rule::in(LabSetting::APPROVAL_LEVELS)],
            'allow_self_review' => ['sometimes', 'boolean'],
            'collect_requires_payment' => ['sometimes', 'boolean'],
            'release_partial_reports' => ['sometimes', 'boolean'],
            'critical_call_required' => ['sometimes', 'boolean'],
            'urgent_tat_hours' => ['sometimes', 'nullable', 'integer', 'between:1,720'],
            'stat_tat_minutes' => ['sometimes', 'nullable', 'integer', 'between:5,1440'],
            'label_size' => ['sometimes', 'string', Rule::in(LabSetting::LABEL_SIZES)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'urgent_tat_hours.between' => 'Urgent turnaround is between 1 hour and 30 days.',
            'stat_tat_minutes.between' => 'STAT turnaround is between 5 minutes and 24 hours.',
        ];
    }
}
