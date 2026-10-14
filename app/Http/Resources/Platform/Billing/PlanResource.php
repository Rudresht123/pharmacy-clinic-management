<?php

namespace App\Http\Resources\Platform\Billing;

use App\Models\Platform\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A plan as the Super Admin's pricing screens see it — everything, including
 * what is never shown on the website.
 *
 * @mixin Plan
 */
class PlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status,
            'is_public' => $this->is_public,
            'is_system' => $this->is_system,
            'sort_order' => $this->sort_order,
            'currency' => $this->currency,
            'monthly_price' => $this->monthly_price,
            'annual_price' => $this->annual_price,
            'trial_days' => $this->trial_days,
            'grace_days' => $this->grace_days,
            'features' => $this->features ?? [],
            'highlights' => $this->highlights ?? [],
            'limits' => $this->limitMap(),
            'modules' => $this->moduleKeys(),
            'subscribers' => $this->whenCounted('subscriptions'),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
