<?php

namespace App\Models\Platform;

use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something on a subscription beyond its plan: a module add-on, an add-on
 * (capacity, credits, a feature), or a Super Admin override.
 */
class SubscriptionItem extends Model
{
    use RecordsHistory;

    public const MODULE = 'module';

    public const ADDON = 'addon';

    public const OVERRIDE = 'override';

    public const ACTIVE = 'active';

    public const PENDING = 'pending';

    public const ENDED = 'ended';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'subscription_id',
        'type',
        'ref',
        'target',
        'quantity',
        'grants',
        'unit_price',
        'billing',
        'starts_at',
        'ends_at',
        'status',
        'note',
        'granted_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'grants' => 'integer',
            'unit_price' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** In force now: active, started, not ended. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', self::ACTIVE)
            ->where('starts_at', '<=', now())
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    public function isLive(): bool
    {
        return $this->status === self::ACTIVE
            && $this->starts_at !== null && ! $this->starts_at->isFuture()
            && ($this->ends_at === null || $this->ends_at->isFuture());
    }

    protected function historyOrganizationId(): ?int
    {
        return $this->subscription?->organization_id;
    }

    protected function historyLabel(): ?string
    {
        return "Subscription item: {$this->type} {$this->ref}";
    }
}
