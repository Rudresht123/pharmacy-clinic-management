<?php

namespace App\Models\Platform;

use App\Models\Record;
use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Builder;

/**
 * Something a clinic buys on top of its plan: capacity, a credit pack, or a
 * feature. Module add-ons are priced in ModulePrice instead, so a module has
 * exactly one price.
 */
class Addon extends Record
{
    use RecordsHistory;

    public const RESOURCE = 'resource';

    public const CREDITS = 'credits';

    public const FEATURE = 'feature';

    public const KINDS = [self::RESOURCE, self::CREDITS, self::FEATURE];

    public const RECURRING = 'recurring';

    public const ONE_TIME = 'one_time';

    protected $fillable = [
        'key',
        'name',
        'description',
        'kind',
        'target',
        'unit_quantity',
        'billing',
        'currency',
        'monthly_price',
        'annual_price',
        'provider_cost',
        'status',
        'is_public',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'unit_quantity' => 'integer',
            'monthly_price' => 'integer',
            'annual_price' => 'integer',
            'provider_cost' => 'integer',
            'is_public' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'key';
    }

    public function scopePurchasable(Builder $query): Builder
    {
        return $query->where('status', Plan::PUBLISHED);
    }

    public function scopeOnWebsite(Builder $query): Builder
    {
        return $query->purchasable()->where('is_public', true)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Price for one unit. A one-time pack costs the same whichever cycle the
     * subscription is on, so it reads the monthly column.
     */
    public function priceFor(string $cycle): int
    {
        if ($this->billing === self::ONE_TIME) {
            return (int) $this->monthly_price;
        }

        return $cycle === Subscription::ANNUAL ? (int) $this->annual_price : (int) $this->monthly_price;
    }

    protected function historyLabel(): ?string
    {
        return "Add-on: {$this->name}";
    }
}
