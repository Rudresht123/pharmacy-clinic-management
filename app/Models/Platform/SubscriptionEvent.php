<?php

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing that happened to a subscription — append-only.
 *
 * Written by SubscriptionLifecycle alone. `dedupe_key` makes a repeated
 * scheduler run harmless: the same transition for the same term writes the
 * same key, and the unique index refuses the second.
 */
class SubscriptionEvent extends Model
{
    public const CREATED = 'created';

    public const TRIAL_STARTED = 'trial_started';

    public const ACTIVATED = 'activated';

    public const RENEWED = 'renewed';

    public const PLAN_CHANGED = 'plan_changed';

    public const PLAN_CHANGE_SCHEDULED = 'plan_change_scheduled';

    public const EXTENDED = 'extended';

    public const PAST_DUE = 'past_due';

    public const GRACE_STARTED = 'grace_started';

    public const EXPIRED = 'expired';

    public const LOCKED = 'locked';

    public const UNLOCKED = 'unlocked';

    public const SUSPENDED = 'suspended';

    public const CANCELLED = 'cancelled';

    public const ADDON_ADDED = 'addon_added';

    public const ADDON_REMOVED = 'addon_removed';

    public const CREDITS_GRANTED = 'credits_granted';

    public const LIMIT_OVERRIDDEN = 'limit_overridden';

    public const FEATURE_GRANTED = 'feature_granted';

    public const PAYMENT_RECEIVED = 'payment_received';

    public const REMINDER_SENT = 'reminder_sent';

    public const ACTOR_PLATFORM = 'platform';

    public const ACTOR_TENANT = 'tenant';

    public const ACTOR_SYSTEM = 'system';

    protected $fillable = [
        'subscription_id',
        'organization_id',
        'type',
        'from_status',
        'to_status',
        'actor_type',
        'actor_id',
        'actor_name',
        'reason',
        'before',
        'after',
        'dedupe_key',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class)->withTrashed();
    }
}
