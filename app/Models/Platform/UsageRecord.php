<?php

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One billable event in the usage ledger. Written once — the idempotency key
 * refuses a second — and never deleted; a reversal is its own row.
 */
class UsageRecord extends Model
{
    public const RECORDED = 'recorded';

    public const REVERSED = 'reversed';

    public const RECONCILED = 'reconciled';

    protected $fillable = [
        'organization_id',
        'subscription_id',
        'channel',
        'provider',
        'category',
        'reference_type',
        'reference_id',
        'quantity',
        'unit',
        'usage_rate_id',
        'provider_cost_e4',
        'price_e4',
        'from_quota',
        'overage',
        'charge_paise',
        'status',
        'reverses_id',
        'occurred_at',
        'idempotency_key',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'provider_cost_e4' => 'integer',
            'price_e4' => 'integer',
            'from_quota' => 'integer',
            'overage' => 'integer',
            'charge_paise' => 'integer',
            'occurred_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class)->withTrashed();
    }

    public function rate(): BelongsTo
    {
        return $this->belongsTo(UsageRate::class, 'usage_rate_id');
    }
}
