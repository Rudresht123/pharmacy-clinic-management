<?php

namespace App\Services\Subscriptions;

use App\Models\Platform\Organization;
use App\Models\Platform\Subscription;
use App\Models\Platform\SubscriptionReminderDelivery;
use App\Services\Notifications\EmailService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells an organization's owner their subscription is ending, has ended, or
 * has locked — once per reminder, per channel, per term.
 *
 * The delivery row is written FIRST, with ON CONFLICT DO NOTHING on (the
 * subscription, the reminder, the term's end, the channel). Only the call that
 * wrote it sends. So a scheduler that asks every hour, two servers asking at
 * once, or a run retried after a crash all produce one email.
 *
 * A provider failure is written on that row and goes no further: it never
 * touches the subscription, and it is not retried into somebody's inbox five
 * times. The admin screen shows failed reminders.
 *
 * The in-app half of every reminder is not sent at all — the dashboard card
 * and banner read the subscription live, so they are never out of date.
 */
class SubscriptionNotifier
{
    public function __construct(
        private readonly BillingSettings $settings,
        private readonly SubscriptionClock $clock,
    ) {}

    /**
     * @param  string  $kind  before_7 | before_3 | … | expires_today | expired | grace_1 | locked | renewed
     */
    public function remind(Subscription $subscription, string $kind, ?CarbonInterface $now = null): int
    {
        $organization = $subscription->organization;
        $periodEnd = $subscription->expires_at ?? $subscription->started_at;
        $sent = 0;

        foreach ((array) $this->settings->get('reminder_channels') as $channel) {
            $recipient = $this->recipient($organization, $channel);

            $claimed = SubscriptionReminderDelivery::query()->insertOrIgnore([
                'subscription_id' => $subscription->id,
                'organization_id' => $organization->getKey(),
                'kind' => $kind,
                'period_end' => $periodEnd,
                'channel' => $channel,
                'recipient' => $recipient,
                'status' => SubscriptionReminderDelivery::PENDING,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            if ($claimed !== 1) {
                continue;
            }

            $delivery = SubscriptionReminderDelivery::query()
                ->where('subscription_id', $subscription->id)
                ->where('kind', $kind)
                ->where('period_end', $periodEnd)
                ->where('channel', $channel)
                ->firstOrFail();

            $this->deliver($delivery, $subscription, $organization, $kind, $channel, $recipient, $now);

            if ($delivery->status === SubscriptionReminderDelivery::SENT) {
                $sent++;
            }
        }

        return $sent;
    }

    private function deliver(
        SubscriptionReminderDelivery $delivery,
        Subscription $subscription,
        Organization $organization,
        string $kind,
        string $channel,
        ?string $recipient,
        ?CarbonInterface $now,
    ): void {
        if ($recipient === null) {
            $delivery->forceFill(['status' => SubscriptionReminderDelivery::SKIPPED, 'error' => 'No address to send to.'])->save();

            return;
        }

        if ($channel !== 'email') {
            /*
             * WhatsApp reminders need an approved template on the platform's
             * own WhatsApp account, and must not go through the clinic's
             * metered sending. Until that template exists, they are recorded
             * as skipped rather than pretended.
             */
            $delivery->forceFill(['status' => SubscriptionReminderDelivery::SKIPPED, 'error' => 'WhatsApp reminders need an approved platform template.'])->save();

            return;
        }

        try {
            [$template, $placeholders] = $this->email($subscription, $organization, $kind, $now);

            $ok = EmailService::send($template, $recipient, $placeholders);

            $delivery->forceFill($ok
                ? ['status' => SubscriptionReminderDelivery::SENT, 'sent_at' => Carbon::now()]
                : ['status' => SubscriptionReminderDelivery::FAILED, 'error' => 'The mail provider did not accept it; see the mail log.']
            )->save();
        } catch (Throwable $e) {
            Log::warning('[subscriptions] reminder failed', ['delivery' => $delivery->id, 'error' => $e->getMessage()]);

            $delivery->forceFill([
                'status' => SubscriptionReminderDelivery::FAILED,
                'error' => mb_substr($e->getMessage(), 0, 500),
            ])->save();
        }
    }

    /** @return array{0: string, 1: array<string, string>} */
    private function email(Subscription $subscription, Organization $organization, string $kind, ?CarbonInterface $now): array
    {
        $timezone = $organization->timezone ?: (string) config('app.timezone');
        $date = fn (?CarbonInterface $at) => $at ? Carbon::instance($at)->setTimezone($timezone)->format('j F Y') : '';
        $plural = fn (?int $n, string $unit) => $n === null ? '' : ($n === 0 ? 'today' : "{$n} {$unit}".($n === 1 ? '' : 's').' left');

        $common = [
            'organization_name' => (string) $organization->organization_name,
            'plan_name' => (string) ($subscription->snapshot['plan']['name'] ?? 'subscription'),
            'renew_button' => emailButton($this->subscriptionUrl($organization), 'Renew subscription'),
        ];

        $days = $this->clock->daysRemaining($subscription, $timezone, $now);

        return match (true) {
            $kind === 'locked' => ['subscription_locked', $common + [
                'locked_on' => $date($subscription->locked_at ?? $this->clock->locksAt($subscription)),
            ]],

            $kind === 'expired' || str_starts_with($kind, 'grace_') => ['subscription_grace', $common + [
                'expired_on' => $date($subscription->expires_at),
                'grace_ends_on' => $date($this->clock->graceEnds($subscription)),
                'grace_left' => $plural($this->clock->graceDaysRemaining($subscription, $timezone, $now), 'day'),
            ]],

            default => ['subscription_expiring', $common + [
                'ends_when' => $days === 0 ? 'today' : ($days === 1 ? 'tomorrow' : "in {$days} days"),
                'expires_on' => $date($subscription->expires_at),
                'days_left' => $plural($days, 'day'),
            ]],
        };
    }

    private function recipient(Organization $organization, string $channel): ?string
    {
        return match ($channel) {
            'email' => filled($organization->email) ? (string) $organization->email : null,
            'whatsapp' => filled($organization->phone_number) ? (string) $organization->phone_number : null,
            default => null,
        };
    }

    /** The clinic's own subscription page, on its own subdomain. */
    private function subscriptionUrl(Organization $organization): string
    {
        $domain = config('organization.main_domain');
        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';

        return $domain
            ? $scheme.'://'.$organization->subdomain.'.'.$domain.'/subscription'
            : url('/subscription');
    }
}
