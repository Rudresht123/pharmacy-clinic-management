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
 *
 * WHO IT TELLS. `audience` says whose wording the rule sends — the patient's
 * ("Your appointment is confirmed") or the clinic's own people's ("Rahul is
 * booked with Dr. Anjali") — so one event can have one of each per channel.
 * `recipients` names the people, by place rather than by name where it can:
 * the patient, the visit's doctor, whoever booked it, the branch manager, the
 * front desk, a role, a named member of staff, an address
 * (App\Support\Communication\RuleRecipients). Null is the patient alone —
 * every rule before there was a choice.
 */
class AutomationRule extends Model
{
    use RecordsHistory, SoftDeletes;

    /** The patient's own wording, to the patient — and whoever is copied in. */
    public const PATIENT = 'patient';

    /** The clinic's people, told in their own words. Never the patient. */
    public const STAFF = 'staff';

    public const AUDIENCES = [self::PATIENT, self::STAFF];

    public const APPOINTMENT_CONFIRMED = 'appointment.confirmed';

    public const APPOINTMENT_REMINDER_DAY = 'appointment.reminder_day';

    public const APPOINTMENT_REMINDER_HOUR = 'appointment.reminder_hour';

    public const APPOINTMENT_CANCELLED = 'appointment.cancelled';

    public const PAYMENT_RECEIVED = 'payment.received';

    public const LAB_REPORT_READY = 'lab.report_ready';

    public const PATIENT_REGISTERED = 'patient.registered';

    /** A patient asked, from the app, to move a booking. Staff only. */
    public const APPOINTMENT_RESCHEDULE_REQUESTED = 'appointment.reschedule_requested';

    /** The clinic said yes and the visit moved. */
    public const APPOINTMENT_RESCHEDULED = 'appointment.rescheduled';

    /** The clinic said no; the visit stays where it was. */
    public const APPOINTMENT_RESCHEDULE_REJECTED = 'appointment.reschedule_rejected';

    /** The desk checked the patient in. */
    public const CHECKED_IN = 'queue.checked_in';

    /** A doctor's queue called the patient's token. */
    public const TOKEN_CALLED = 'queue.token_called';

    /** The doctor completed the consultation. */
    public const CONSULTATION_COMPLETED = 'queue.consultation_completed';

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
        'recipients',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'lead_minutes' => 'integer',
            'sort_order' => 'integer',
            'recipients' => 'array',
        ];
    }

    public function isForStaff(): bool
    {
        return $this->audience === self::STAFF;
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
