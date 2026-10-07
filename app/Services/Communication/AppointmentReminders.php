<?php

namespace App\Services\Communication;

use App\Models\Platform\Organization;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\AutomationDelivery;
use App\Models\Tenant\AutomationRule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The reminders that fire on a clock: "your appointment is tomorrow", "in an
 * hour".
 *
 * Driven entirely by the clinic's own automation rules. Each reminder rule
 * carries `lead_minutes` — how long before the appointment it goes — and the
 * same switches as every automated message (AutomationSender::send): the
 * module, the connected channel, the rule itself, an approved template, an
 * address. This decides only WHICH appointments are due; whether anything
 * actually goes out is AutomationSender's answer, per channel.
 *
 * DUE MEANS: a booked appointment, still only booked (not arrived, not
 * cancelled, not a no-show), whose start minus the lead has passed and whose
 * start has not — AND which was booked before that moment. Somebody who booked
 * twenty minutes ago for half an hour's time already has their confirmation;
 * "one hour to go" would be wrong on both counts.
 *
 * Runs in the tenant the caller connected (the console command walks them).
 */
class AppointmentReminders
{
    /** The rules this runs. Both carry their own `lead_minutes`. */
    public const RULES = [
        AutomationRule::APPOINTMENT_REMINDER_DAY,
        AutomationRule::APPOINTMENT_REMINDER_HOUR,
    ];

    public function __construct(
        private readonly AutomationSender $sender,
    ) {}

    /**
     * Send every reminder that is due now.
     *
     * @return int how many messages were queued
     */
    public function sendDue(Organization $organization, ?Carbon $now = null): int
    {
        $now ??= Carbon::now();
        $queued = 0;

        $rules = AutomationRule::query()
            ->whereIn('event_key', self::RULES)
            ->where('is_enabled', true)
            ->where('lead_minutes', '>', 0)
            ->orderBy('sort_order')
            ->get();

        foreach ($rules as $rule) {
            foreach ($this->dueFor((int) $rule->lead_minutes, $now) as $visit) {
                $queued += $this->remind($organization, $rule, $visit);
            }
        }

        return $queued;
    }

    /**
     * One reminder, at most once.
     *
     * Claimed before sending so an overlapping run cannot send it twice, and
     * released when nothing went out — a switch was off — so the reminder is
     * still sent if somebody switches it on before the appointment.
     */
    private function remind(Organization $organization, AutomationRule $rule, Appointment $visit): int
    {
        $channel = (string) $rule->channel;

        if ($visit->customer === null || ! AutomationDelivery::claim($rule->event_key, $channel, $visit)) {
            return 0;
        }

        $sent = $this->sender->send(
            $organization,
            $rule->event_key,
            $visit->customer,
            $visit->location_id === null ? null : (int) $visit->location_id,
            $this->sender->appointmentVariables($visit, $organization),
            $channel,
        );

        if ($sent === []) {
            AutomationDelivery::release($rule->event_key, $channel, $visit);
        }

        return count($sent);
    }

    /**
     * Appointments whose reminder `$lead` minutes ahead is due at `$now`.
     *
     * The date filter is done in SQL to keep the candidate set to a day or
     * two; the exact moment, which needs the slot time, in PHP.
     *
     * @return Collection<int, Appointment>
     */
    private function dueFor(int $lead, Carbon $now): Collection
    {
        return Appointment::query()
            ->with(['customer', 'doctor', 'location'])
            ->where('type', Appointment::BOOKED)
            ->where('status', Appointment::STATUS_BOOKED)
            ->whereNotNull('slot_at')
            ->whereBetween('appointment_date', [
                $now->toDateString(),
                $now->copy()->addMinutes($lead)->toDateString(),
            ])
            ->get()
            ->filter(function (Appointment $visit) use ($lead, $now) {
                $start = $this->startOf($visit);
                $moment = $start->copy()->subMinutes($lead);

                return $moment->lessThanOrEqualTo($now)
                    && $start->greaterThan($now)
                    && $visit->created_at !== null
                    && $visit->created_at->lessThanOrEqualTo($moment);
            })
            ->values();
    }

    /** When the appointment begins, on the clinic's clock. */
    private function startOf(Appointment $visit): Carbon
    {
        return Carbon::parse(
            $visit->appointment_date->toDateString().' '.substr((string) $visit->slot_at, 0, 5),
        );
    }
}
