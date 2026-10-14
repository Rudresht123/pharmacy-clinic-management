<?php

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One expiry reminder, on one channel, for one term. The unique key
 * (subscription, kind, period_end, channel) is the deduplication.
 */
class SubscriptionReminderDelivery extends Model
{
    public const PENDING = 'pending';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    protected $fillable = [
        'subscription_id',
        'organization_id',
        'kind',
        'period_end',
        'channel',
        'recipient',
        'status',
        'error',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'period_end' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
