<?php

namespace App\Models\Tenant;

use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One thing a clinic decided to say, to many people, now or later.
 *
 * `scheduled_at` is the feature. A campaign carrying one is picked up by
 * `campaigns:dispatch` when its time comes, with nobody present — which is
 * also why `sending` is a status of its own: a dispatcher that dies half way
 * leaves evidence it was running, rather than a campaign that looks scheduled
 * forever and silently never went.
 *
 * The sender and the audience filters are COPIED onto the row. A clinic that
 * changes its from-address, or edits the segment, must not change what an
 * already-scheduled campaign will claim or who it will reach.
 */
class Campaign extends Model
{
    use RecordsHistory, SoftDeletes;

    public const DRAFT = 'draft';

    public const SCHEDULED = 'scheduled';

    public const SENDING = 'sending';

    public const COMPLETED = 'completed';

    public const PAUSED = 'paused';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    /** Every value the database CHECK permits. */
    public const STATUSES = [
        self::DRAFT, self::SCHEDULED, self::SENDING,
        self::COMPLETED, self::PAUSED, self::FAILED, self::CANCELLED,
    ];

    /** Statuses a campaign can still be edited or scheduled from. */
    public const EDITABLE = [self::DRAFT, self::SCHEDULED, self::PAUSED];

    protected $connection = 'organization';

    protected $table = 'campaigns';

    protected $fillable = [
        'channel',
        'name',
        'campaign_type',
        'goal',
        'subject',
        'preview_text',
        'message_template_id',
        'content',
        'from_name',
        'from_email',
        'reply_to',
        'audience_segment_id',
        'audience_filters',
        'status',
        'scheduled_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'audience_filters' => 'array',
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'recipients_count' => 'integer',
            'sent_count' => 'integer',
            'delivered_count' => 'integer',
            'opened_count' => 'integer',
            'clicked_count' => 'integer',
            'failed_count' => 'integer',
        ];
    }

    /**
     * Campaigns whose time has come.
     *
     * The dispatcher's only question. Ordered oldest first so a backlog after
     * an outage goes out in the order it was meant to.
     */
    public function scopeDue(Builder $query, ?Carbon $at = null): Builder
    {
        return $query->where('status', self::SCHEDULED)
            ->where('scheduled_at', '<=', $at ?? Carbon::now())
            ->orderBy('scheduled_at');
    }

    public function scopeForChannel(Builder $query, string $channel): Builder
    {
        return $query->where('channel', $channel);
    }

    /** Whether this may still be changed, or has already gone out. */
    public function isEditable(): bool
    {
        return in_array($this->status, self::EDITABLE, true);
    }

    /** Opens as a share of what was delivered, not of what was sent. */
    public function openRate(): ?float
    {
        return $this->delivered_count > 0
            ? round(($this->opened_count / $this->delivered_count) * 100, 1)
            : null;
    }

    public function clickRate(): ?float
    {
        return $this->delivered_count > 0
            ? round(($this->clicked_count / $this->delivered_count) * 100, 1)
            : null;
    }

    public function segment(): BelongsTo
    {
        return $this->belongsTo(AudienceSegment::class, 'audience_segment_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class, 'message_template_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    protected function historyLabel(): ?string
    {
        return $this->name;
    }
}
