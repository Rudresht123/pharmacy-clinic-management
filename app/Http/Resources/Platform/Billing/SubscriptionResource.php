<?php

namespace App\Http\Resources\Platform\Billing;

use App\Models\Platform\Subscription;
use App\Services\Subscriptions\SubscriptionClock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A subscription as the Super Admin sees it in lists: the stored row plus
 * what the clock says about it now — the two can differ until the scheduler
 * catches up, and the effective one is what the clinic is living with.
 *
 * @mixin Subscription
 */
class SubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $clock = app(SubscriptionClock::class);
        $timezone = $this->organization?->timezone ?: (string) config('app.timezone');

        return [
            'uuid' => $this->uuid,
            'organization' => $this->whenLoaded('organization', fn () => [
                'uuid' => $this->organization->uuid,
                'name' => $this->organization->organization_name,
                'code' => $this->organization->organization_code,
                'status' => $this->organization->status,
                'email' => $this->organization->email,
            ]),
            'plan' => [
                'id' => $this->plan_id,
                'name' => $this->snapshot['plan']['name'] ?? null,
                'slug' => $this->snapshot['plan']['slug'] ?? null,
            ],
            'status' => $this->status,
            'effective_status' => $clock->status($this->resource),
            'locked' => $clock->isLocked($this->resource),
            'lock_reason' => $this->lock_reason,
            'billing_cycle' => $this->billing_cycle,
            'is_current' => $this->is_current,
            'price' => $this->snapshotPrice(),
            'currency' => $this->snapshot['currency'] ?? 'INR',
            'payment_status' => $this->payment_status,
            'started_at' => $this->started_at?->toIso8601String(),
            'trial_ends_at' => $this->trial_ends_at?->toIso8601String(),
            'current_period_start' => $this->current_period_start?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'grace_ends_at' => $clock->graceEnds($this->resource)?->toIso8601String(),
            'next_renewal_at' => $this->next_renewal_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'suspended_at' => $this->suspended_at?->toIso8601String(),
            'suspension_reason' => $this->suspension_reason,
            'locked_at' => $this->locked_at?->toIso8601String(),
            'days_remaining' => $clock->daysRemaining($this->resource, $timezone),
            'pending_plan' => $this->pendingPlan?->name,
            'pending_billing_cycle' => $this->pending_billing_cycle,
            'snapshot' => $this->snapshot,
        ];
    }
}
