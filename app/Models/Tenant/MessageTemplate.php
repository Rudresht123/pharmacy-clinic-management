<?php

namespace App\Models\Tenant;

use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Wording a clinic is allowed to send.
 *
 * `status` is the provider's answer rather than ours — WhatsApp approves or
 * rejects a template and nothing here can overrule it — which is why a
 * rejected template is kept and shown rather than deleted. The clinic needs to
 * see what was refused in order to rewrite it.
 *
 * Placeholders are {{1}}, {{2}} in WhatsApp's own numbering, or named ones
 * like {{patient_name}} where the provider allows it. Neither is resolved
 * here: filling them is the sender's job, at the moment of sending.
 */
class MessageTemplate extends Model
{
    use RecordsHistory, SoftDeletes;

    public const DRAFT = 'draft';

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    /** Every value the database CHECK permits. */
    public const STATUSES = [self::DRAFT, self::PENDING, self::APPROVED, self::REJECTED];

    protected $connection = 'organization';

    protected $table = 'message_templates';

    protected $fillable = [
        'channel',
        'name',
        'category',
        'template_type',
        'subject',
        'content',
        'status',
        'icon',
        'tone',
        'is_active',
        'sort_order',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** One channel's library, in the order the screen shows it. */
    public function scopeForChannel(Builder $query, string $channel): Builder
    {
        return $query->where('channel', $channel)->orderBy('sort_order')->orderBy('id');
    }

    /** Only what may actually be sent. */
    public function scopeSendable(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('status', self::APPROVED);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    protected function historyLabel(): ?string
    {
        return $this->name;
    }
}
