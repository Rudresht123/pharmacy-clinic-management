<?php

namespace App\Models\Tenant;

use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * When a message goes out without anybody asking.
 *
 * `event_key` is what the application looks a rule up by; the title is wording
 * somebody may edit. Keeping them apart is what lets a clinic rename "Send
 * 24-hour reminder" to whatever they call it without the appointment code
 * losing track of which rule that is.
 *
 * A rule with no template cannot fire. That is deliberate rather than an
 * oversight to guard against — switching a rule on before choosing what it
 * sends should be possible, and is caught when it tries to send.
 */
class AutomationRule extends Model
{
    use RecordsHistory, SoftDeletes;

    public const APPOINTMENT_CONFIRMED = 'appointment.confirmed';

    public const APPOINTMENT_REMINDER_DAY = 'appointment.reminder_day';

    public const APPOINTMENT_REMINDER_HOUR = 'appointment.reminder_hour';

    public const APPOINTMENT_CANCELLED = 'appointment.cancelled';

    public const PAYMENT_RECEIVED = 'payment.received';

    protected $connection = 'organization';

    protected $table = 'automation_rules';

    protected $fillable = [
        'channel',
        'event_key',
        'title',
        'description',
        'icon',
        'message_template_id',
        'is_enabled',
        'lead_minutes',
        'sort_order',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'lead_minutes' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function scopeForChannel(Builder $query, string $channel): Builder
    {
        return $query->where('channel', $channel)->orderBy('sort_order')->orderBy('id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class, 'message_template_id');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    protected function historyLabel(): ?string
    {
        return $this->title;
    }
}
