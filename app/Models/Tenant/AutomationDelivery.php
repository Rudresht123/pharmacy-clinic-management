<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/**
 * "This automated message has been sent about this record, on this channel."
 *
 * The once-only guarantee for messages that fire on a CLOCK rather than an
 * event — appointment reminders. A scheduler that runs every few minutes asks
 * the same question every few minutes; the unique row is what makes the
 * second asking a no-op instead of a second reminder on the patient's phone.
 *
 * Claimed BEFORE sending (insert-or-ignore), so two schedulers overlapping —
 * a slow run and the next one — cannot both send. Released again when nothing
 * was actually queued (a switch was off), so switching the rule on later in
 * the window still reaches the patient.
 */
class AutomationDelivery extends Model
{
    protected $connection = 'organization';

    protected $table = 'automation_deliveries';

    public const UPDATED_AT = null;

    protected $fillable = [
        'event_key',
        'channel',
        'subject_type',
        'subject_id',
    ];

    protected function casts(): array
    {
        return [
            'subject_id' => 'integer',
        ];
    }

    /**
     * Take the right to send this one. False when somebody already has.
     */
    public static function claim(string $eventKey, string $channel, Model $subject): bool
    {
        return static::query()->insertOrIgnore([
            'event_key' => $eventKey,
            'channel' => $channel,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'created_at' => now(),
        ]) === 1;
    }

    /** Give it back — nothing went out, so it may be tried again. */
    public static function release(string $eventKey, string $channel, Model $subject): void
    {
        static::query()
            ->where('event_key', $eventKey)
            ->where('channel', $channel)
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->delete();
    }
}
