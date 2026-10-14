<?php

namespace App\Models\Platform;

use App\Models\Record;
use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Builder;

/**
 * What one unit of WhatsApp, SMS, email or video costs, and sells for.
 *
 * Amounts are ten-thousandths of a rupee (`_e4`): ₹0.1150 is 1150. Versioned —
 * a new rate takes over from a date and the old row stays, so every usage
 * record can name the exact rate it was charged at.
 */
class UsageRate extends Record
{
    use RecordsHistory;

    public const CHANNELS = ['whatsapp', 'sms', 'email', 'video'];

    public const UNITS = ['message', 'segment', 'email', 'minute', 'session'];

    public const BLOCK = 'block';

    public const ALLOW = 'allow';

    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    public const ARCHIVED = 'archived';

    protected $fillable = [
        'channel',
        'provider',
        'category',
        'destination',
        'billing_unit',
        'currency',
        'provider_cost_e4',
        'price_e4',
        'overage_policy',
        'charge_failed',
        'tiers',
        'status',
        'notes',
        'effective_from',
        'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'provider_cost_e4' => 'integer',
            'price_e4' => 'integer',
            'charge_failed' => 'boolean',
            'tiers' => 'array',
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
        ];
    }

    public function scopeInForce(Builder $query): Builder
    {
        return $query->where('status', self::PUBLISHED)
            ->where('effective_from', '<=', now())
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', now()));
    }

    /**
     * The per-unit price for the Nth unit of this period, honouring volume
     * tiers. `$alreadyUsed` is how many units the period has spent so far.
     */
    public function unitPriceAt(int $alreadyUsed): int
    {
        $tiers = collect($this->tiers ?? [])
            ->filter(fn ($tier) => isset($tier['from'], $tier['price_e4']))
            ->sortBy('from');

        $price = (int) $this->price_e4;

        foreach ($tiers as $tier) {
            if ($alreadyUsed >= (int) $tier['from']) {
                $price = (int) $tier['price_e4'];
            }
        }

        return $price;
    }

    protected function historyLabel(): ?string
    {
        return "Usage rate: {$this->channel}".($this->category ? " ({$this->category})" : '');
    }
}
