<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One patient's copy of a campaign.
 *
 * Written when the campaign is dispatched, from the segment resolved at that
 * moment, and kept afterwards — so "who did this actually reach" survives the
 * segment being edited later.
 *
 * Not a Record: a recipient row is something that happened, not something
 * anybody maintains.
 */
class CampaignRecipient extends Model
{
    /**
     * So these go when their campaign does.
     *
     * The cascade on `campaign_id` never fires, because a campaign soft
     * deletes rather than leaving the table — without a `deleted_at` here the
     * children of a removed campaign would stay visible after the parent went.
     */
    use SoftDeletes;

    public const PENDING = 'pending';

    public const SENT = 'sent';

    public const DELIVERED = 'delivered';

    public const OPENED = 'opened';

    public const CLICKED = 'clicked';

    public const FAILED = 'failed';

    /** Resolved into the audience, then ruled out — no consent, no address. */
    public const SKIPPED = 'skipped';

    public const STATUSES = [
        self::PENDING, self::SENT, self::DELIVERED,
        self::OPENED, self::CLICKED, self::FAILED, self::SKIPPED,
    ];

    protected $connection = 'organization';

    protected $table = 'campaign_recipients';

    protected $fillable = [
        'campaign_id',
        'customer_id',
        'recipient_name',
        'recipient',
        'status',
        'failure_reason',
        'sent_at',
        'opened_at',
        'clicked_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'opened_at' => 'datetime',
            'clicked_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
