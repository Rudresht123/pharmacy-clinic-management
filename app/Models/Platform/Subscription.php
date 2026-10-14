<?php

namespace App\Models\Platform;

use App\Models\Record;
use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One organization's contract with the platform.
 *
 * Written ONLY by App\Services\Subscriptions\SubscriptionLifecycle, which is
 * where the transitions, the events and the cache version live. Nothing else
 * should save one of these — a status set from a controller is a status with
 * no event, no audit reason and a stale entitlement cache.
 *
 * `status` is what the lifecycle last persisted. Whether the organization may
 * work RIGHT NOW is EntitlementService::effectiveStatus(), which reads the
 * dates — so a scheduler that has not run yet cannot keep a lapsed clinic open.
 */
class Subscription extends Record
{
    use RecordsHistory;

    public const TRIAL = 'trial';

    public const ACTIVE = 'active';

    public const PAST_DUE = 'past_due';

    public const GRACE = 'grace';

    public const EXPIRED = 'expired';

    public const SUSPENDED = 'suspended';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [
        self::TRIAL,
        self::ACTIVE,
        self::PAST_DUE,
        self::GRACE,
        self::EXPIRED,
        self::SUSPENDED,
        self::CANCELLED,
    ];

    public const MONTHLY = 'monthly';

    public const ANNUAL = 'annual';

    public const COMPLIMENTARY = 'complimentary';

    public const CYCLES = [self::MONTHLY, self::ANNUAL, self::COMPLIMENTARY];

    /** Why it is locked — only `expired` is lifted by paying. */
    public const LOCK_EXPIRED = 'expired';

    public const LOCK_BILLING_SUSPENDED = 'billing_suspended';

    public const PAYMENT_NONE = 'none';

    public const PAYMENT_PENDING = 'pending';

    public const PAYMENT_PAID = 'paid';

    public const PAYMENT_FAILED = 'failed';

    public const PAYMENT_WAIVED = 'waived';

    protected $fillable = [
        'organization_id',
        'plan_id',
        'status',
        'billing_cycle',
        'is_current',
        'started_at',
        'trial_starts_at',
        'trial_ends_at',
        'current_period_start',
        'current_period_end',
        'expires_at',
        'grace_ends_at',
        'next_renewal_at',
        'cancelled_at',
        'locked_at',
        'lock_reason',
        'suspended_at',
        'suspension_reason',
        'payment_status',
        'snapshot',
        'entitlements_version',
        'pending_plan_id',
        'pending_billing_cycle',
        'replaced_by_id',
    ];

    protected function casts(): array
    {
        return [
            'is_current' => 'boolean',
            'started_at' => 'datetime',
            'trial_starts_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'expires_at' => 'datetime',
            'grace_ends_at' => 'datetime',
            'next_renewal_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'locked_at' => 'datetime',
            'suspended_at' => 'datetime',
            'snapshot' => 'array',
            'entitlements_version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        parent::booted();

        static::creating(function (self $subscription) {
            $subscription->uuid ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class)->withTrashed();
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function pendingPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'pending_plan_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SubscriptionItem::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(SubscriptionEvent::class)->orderByDesc('occurred_at')->orderByDesc('id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(SubscriptionInvoice::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('is_current', true);
    }

    /*
    |--------------------------------------------------------------------------
    | The agreement — read from the snapshot, never from the plan
    |--------------------------------------------------------------------------
    */

    public function isComplimentary(): bool
    {
        return $this->billing_cycle === self::COMPLIMENTARY;
    }

    /** @return list<string> */
    public function snapshotModules(): array
    {
        return array_values((array) ($this->snapshot['modules'] ?? []));
    }

    /** @return list<string> */
    public function snapshotFeatures(): array
    {
        return array_values((array) ($this->snapshot['features'] ?? []));
    }

    /** null = unlimited, 0 = none. */
    public function snapshotLimit(string $key): ?int
    {
        $limits = (array) ($this->snapshot['limits'] ?? []) + (array) ($this->snapshot['quotas'] ?? []);

        if (! array_key_exists($key, $limits)) {
            return 0;
        }

        return $limits[$key] === null ? null : (int) $limits[$key];
    }

    public function snapshotPrice(): int
    {
        return (int) ($this->snapshot['price'] ?? 0);
    }

    public function graceDays(): int
    {
        return (int) ($this->snapshot['grace_days'] ?? 0);
    }

    /** A cache counter, not a decision anybody made. */
    protected function historyExcept(): array
    {
        return ['entitlements_version'];
    }

    /** The billing contract's history belongs to its organization. */
    protected function historyOrganizationId(): ?int
    {
        return $this->organization_id;
    }

    protected function historyLabel(): ?string
    {
        return 'Subscription: '.($this->snapshot['plan']['name'] ?? $this->plan?->name ?? "#{$this->id}");
    }
}
