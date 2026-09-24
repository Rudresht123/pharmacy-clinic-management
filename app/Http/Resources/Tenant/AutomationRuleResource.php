<?php

namespace App\Http\Resources\Tenant;

use App\Models\Tenant\AutomationRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AutomationRule
 */
class AutomationRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel' => $this->channel,
            'event_key' => $this->event_key,
            'title' => $this->title,
            'description' => $this->description,
            'icon' => $this->icon,

            'is_enabled' => (bool) $this->is_enabled,
            'lead_minutes' => $this->lead_minutes,

            'message_template_id' => $this->message_template_id,
            'template_name' => $this->whenLoaded('template', fn () => $this->template?->name),
        ];
    }
}
