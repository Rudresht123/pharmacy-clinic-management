<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One message, and what became of it.
 *
 * The record that answers "we never heard from the clinic", which is the
 * question this module exists for — so the recipient and the template's name
 * are COPIED onto the row. A number corrected next month must not rewrite
 * where a message actually went, and a renamed template must not make the
 * history unreadable.
 *
 * Deliberately not a Record: a delivery is an event that happened, not a
 * record somebody maintains, and there is nothing here to edit or restore.
 */
class MessageLog extends Model
{
    /**
     * Hidden, never destroyed.
     *
     * Not a Record — a delivery is an event that happened, not something
     * anybody maintains, so there is no history and no reason field. But a
     * clear has to leave the evidence in the table, or the one question this
     * module exists to answer stops being answerable.
     */
    use SoftDeletes;

    public const QUEUED = 'queued';

    public const SENT = 'sent';

    public const DELIVERED = 'delivered';

    public const READ = 'read';

    public const FAILED = 'failed';

    /** Every value the database CHECK permits, in funnel order. */
    public const STATUSES = [self::QUEUED, self::SENT, self::DELIVERED, self::READ, self::FAILED];

    /**
     * States that mean the message arrived.
     *
     * Read implies delivered — a patient cannot read what never landed — so
     * the delivered count includes it rather than the two being exclusive.
     */
    public const ARRIVED = [self::DELIVERED, self::READ];

    protected $connection = 'organization';

    protected $table = 'message_logs';

    protected $fillable = [
        'channel',
        'provider',
        'provider_message_id',
        'customer_id',
        'message_template_id',
        'campaign_id',
        'scheduled_for',
        'recipient_name',
        'recipient',
        'template_name',
        'subject',
        'status',
        'failure_reason',
        'error_code',
        'attempts',
        'request_payload',
        'response_payload',
        'is_test',
        'sent_at',
        'delivered_at',
        'read_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_test' => 'boolean',
            'scheduled_for' => 'datetime',
            'attempts' => 'integer',
            'request_payload' => 'array',
            'response_payload' => 'array',
            'failed_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    public function scopeForChannel(Builder $query, string $channel): Builder
    {
        return $query->where('channel', $channel);
    }

    /**
     * Clinic traffic only.
     *
     * A test proves the connection works; counting it would put the rates on
     * the dashboard out by however many times somebody pressed the button.
     */
    public function scopeReal(Builder $query): Builder
    {
        return $query->where('is_test', false);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class, 'message_template_id');
    }
}
