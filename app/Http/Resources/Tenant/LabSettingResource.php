<?php

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\LabSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LabSetting
 */
class LabSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'approval_levels' => (int) $this->approval_levels,
            'allow_self_review' => (bool) $this->allow_self_review,
            'collect_requires_payment' => (bool) $this->collect_requires_payment,
            'release_partial_reports' => (bool) $this->release_partial_reports,
            'critical_call_required' => (bool) $this->critical_call_required,
            'urgent_tat_hours' => $this->urgent_tat_hours,
            'stat_tat_minutes' => $this->stat_tat_minutes,
            'label_size' => $this->label_size,

            'updated_at' => $this->updated_at?->toIso8601String(),
            'updated_by_name' => $this->whenLoaded('editor', fn () => $this->editor?->name),
        ];
    }
}
