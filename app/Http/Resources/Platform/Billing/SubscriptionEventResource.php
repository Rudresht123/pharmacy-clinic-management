<?php

namespace App\Http\Resources\Platform\Billing;

use App\Models\Platform\SubscriptionEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SubscriptionEvent */
class SubscriptionEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization' => $this->whenLoaded('organization', fn () => $this->organization?->organization_name),
            'type' => $this->type,
            'from_status' => $this->from_status,
            'to_status' => $this->to_status,
            'actor_type' => $this->actor_type,
            'actor_name' => $this->actor_name,
            'reason' => $this->reason,
            'before' => $this->before,
            'after' => $this->after,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
        ];
    }
}
