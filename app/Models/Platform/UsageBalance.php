<?php

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One quota's allowance for one period, and how much of it is spent.
 *
 * Spent ONLY through UsageMeter, with a conditional UPDATE — never by loading
 * this row, adding to `used` and saving it, which is how two sends racing for
 * the last credit would both get it.
 */
class UsageBalance extends Model
{
    protected $fillable = [
        'organization_id',
        'subscription_id',
        'quota_key',
        'period_start',
        'period_end',
        'included',
        'purchased',
        'granted',
        'used',
        'overage',
        'overage_charge_paise',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'included' => 'integer',
            'purchased' => 'integer',
            'granted' => 'integer',
            'used' => 'integer',
            'overage' => 'integer',
            'overage_charge_paise' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isUnlimited(): bool
    {
        return $this->included === null;
    }

    /** Everything this period may spend; null when unlimited. */
    public function allowance(): ?int
    {
        return $this->included === null ? null : $this->included + $this->purchased + $this->granted;
    }

    /** What is left; null when unlimited. Never negative. */
    public function remaining(): ?int
    {
        $allowance = $this->allowance();

        return $allowance === null ? null : max(0, $allowance - $this->used);
    }
}
