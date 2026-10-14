<?php

namespace App\Models\Platform;

use App\Models\Record;
use App\Support\History\RecordsHistory;
use App\Support\Subscriptions\Entitlements;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A plan on the price list — Starter, Growth, whatever Super Admin calls them.
 *
 * Nothing here is an agreement. What an organization pays and gets is the
 * snapshot on its Subscription, copied from this row when it bought or last
 * renewed; editing a plan changes the next sale, never a running contract.
 */
class Plan extends Record
{
    use RecordsHistory;

    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    public const UNPUBLISHED = 'unpublished';

    public const ARCHIVED = 'archived';

    public const STATUSES = [self::DRAFT, self::PUBLISHED, self::UNPUBLISHED, self::ARCHIVED];

    /** The internal plan existing organizations were rolled onto. */
    public const COMPLIMENTARY = 'complimentary';

    protected $fillable = [
        'slug',
        'name',
        'description',
        'status',
        'is_public',
        'sort_order',
        'currency',
        'monthly_price',
        'annual_price',
        'trial_days',
        'grace_days',
        'features',
        'highlights',
    ];

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
            'is_system' => 'boolean',
            'sort_order' => 'integer',
            'monthly_price' => 'integer',
            'annual_price' => 'integer',
            'trial_days' => 'integer',
            'grace_days' => 'integer',
            'features' => 'array',
            'highlights' => 'array',
        ];
    }

    protected static function booted(): void
    {
        parent::booted();

        static::creating(function (self $plan) {
            $plan->uuid ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function limits(): HasMany
    {
        return $this->hasMany(PlanLimit::class);
    }

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'plan_modules')->withTimestamps();
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** Can be bought: published. Whether the website shows it is `is_public`. */
    public function scopePurchasable(Builder $query): Builder
    {
        return $query->where('status', self::PUBLISHED)->where('is_system', false);
    }

    /** What the website lists. */
    public function scopeOnWebsite(Builder $query): Builder
    {
        return $query->purchasable()->where('is_public', true)->orderBy('sort_order')->orderBy('id');
    }

    public function isPurchasable(): bool
    {
        return $this->status === self::PUBLISHED && ! $this->is_system;
    }

    public function isComplimentary(): bool
    {
        return $this->slug === self::COMPLIMENTARY;
    }

    /** Price for one cycle, in paise. */
    public function priceFor(string $cycle): int
    {
        return match ($cycle) {
            Subscription::ANNUAL => (int) $this->annual_price,
            Subscription::MONTHLY => (int) $this->monthly_price,
            default => 0,
        };
    }

    /**
     * Every limit/quota key with its value — null is unlimited, 0 is none.
     *
     * A key with no row reads as 0: a plan that does not mention SMS grants
     * no SMS, rather than unlimited SMS by omission.
     *
     * @return array<string, int|null>
     */
    public function limitMap(): array
    {
        $rows = $this->relationLoaded('limits') ? $this->limits : $this->limits()->get();
        $byKey = $rows->keyBy('key');

        $map = [];

        foreach (Entitlements::limitKeys() as $key) {
            $row = $byKey->get($key);
            $map[$key] = $row === null ? 0 : ($row->value === null ? null : (int) $row->value);
        }

        return $map;
    }

    /** @return list<string> module keys */
    public function moduleKeys(): array
    {
        $modules = $this->relationLoaded('modules') ? $this->modules : $this->modules()->get();

        return $modules->pluck('key')->values()->all();
    }

    /** What the history log calls this row. */
    protected function historyLabel(): ?string
    {
        return "Plan: {$this->name}";
    }
}
