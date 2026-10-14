<?php

namespace App\Models\Platform;

use App\Models\Record;
use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one module costs as an add-on, from one date.
 *
 * Never edited after it has applied: PricingAdmin closes this row and opens a
 * new one, so the history of a module's price is the rows themselves.
 */
class ModulePrice extends Record
{
    use RecordsHistory;

    protected $fillable = [
        'module_id',
        'currency',
        'monthly_price',
        'annual_price',
        'provider_cost',
        'is_addon_eligible',
        'trial_available',
        'is_public',
        'effective_from',
        'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'monthly_price' => 'integer',
            'annual_price' => 'integer',
            'provider_cost' => 'integer',
            'is_addon_eligible' => 'boolean',
            'trial_available' => 'boolean',
            'is_public' => 'boolean',
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
        ];
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    /** The price in force now: started, and not yet closed. */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('effective_from', '<=', now())
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', now()));
    }

    public function priceFor(string $cycle): int
    {
        return $cycle === Subscription::ANNUAL ? (int) $this->annual_price : (int) $this->monthly_price;
    }

    protected function historyLabel(): ?string
    {
        return 'Module price: '.($this->module?->name ?? "#{$this->module_id}");
    }
}
