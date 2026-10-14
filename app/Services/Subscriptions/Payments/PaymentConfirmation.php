<?php

namespace App\Services\Subscriptions\Payments;

use App\Models\Platform\Plan;
use App\Models\Platform\PublicRegistration;
use App\Models\Platform\Subscription;
use App\Models\Platform\SubscriptionEvent;
use App\Models\Platform\SubscriptionInvoice;
use App\Models\Platform\SubscriptionItem;
use App\Models\Platform\SubscriptionPayment;
use App\Services\Notifications\EmailService;
use App\Services\Subscriptions\Actor;
use App\Services\Subscriptions\PublicSignup;
use App\Services\Subscriptions\SubscriptionLifecycle;
use App\Support\Subscriptions\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The one place verified money becomes a paid invoice — and the invoice's
 * promise is kept: a renewal renews, an upgrade upgrades, add-ons are added.
 *
 * Only ever called after the server has verified the payment (a checked
 * Razorpay signature plus Razorpay's own word on the payment, a checked
 * webhook, or an administrator recording money they have seen).
 *
 * IDEMPOTENT. The invoice row is held for update; a payment already captured,
 * or an invoice already fulfilled, is answered with what already happened. A
 * webhook arriving after the browser's callback — or twice — changes nothing
 * the second time.
 */
class PaymentConfirmation
{
    public function __construct(
        private readonly SubscriptionLifecycle $lifecycle,
    ) {}

    /** @param  array<string, mixed>  $evidence */
    public function confirm(SubscriptionPayment $payment, string $via, array $evidence = [], ?Actor $actor = null): SubscriptionInvoice
    {
        $actor ??= Actor::system('Payment confirmation');

        $invoice = DB::transaction(function () use ($payment, $via, $evidence, $actor) {
            /** @var SubscriptionInvoice $invoice */
            $invoice = SubscriptionInvoice::query()->whereKey($payment->invoice_id)->lockForUpdate()->firstOrFail();
            $payment = SubscriptionPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->status !== SubscriptionPayment::CAPTURED) {
                if ($payment->amount !== $invoice->total) {
                    throw ValidationException::withMessages(['amount' => 'The amount paid does not match the invoice.']);
                }

                $payment->forceFill([
                    'status' => SubscriptionPayment::CAPTURED,
                    'verified_at' => Carbon::now(),
                    'verified_via' => $via,
                    'payload' => $evidence === [] ? $payment->payload : $evidence,
                ])->save();
            }

            if ($invoice->status === SubscriptionInvoice::ISSUED) {
                $invoice->forceFill(['status' => SubscriptionInvoice::PAID, 'paid_at' => Carbon::now()])->save();
            }

            if ($invoice->status === SubscriptionInvoice::VOID) {
                // Money for a voided invoice is recorded and flagged, never
                // silently turned into something nobody asked for.
                Log::warning('[subscriptions] payment captured against a void invoice', ['invoice' => $invoice->id, 'payment' => $payment->id]);

                return $invoice;
            }

            if ($invoice->fulfilled_at === null) {
                $this->fulfil($invoice, $actor);
                $invoice->forceFill(['fulfilled_at' => Carbon::now()])->save();
            }

            return $invoice;
        });

        /*
         * A website sign-up is provisioned only after the commit: the
         * provisioning job runs on a worker, and a worker must never be
         * handed an organization whose payment could still roll back.
         */
        if (($invoice->fulfilment['action'] ?? null) === 'activate_registration') {
            $registration = PublicRegistration::query()->find((int) ($invoice->fulfilment['registration_id'] ?? 0));

            if ($registration !== null) {
                app(PublicSignup::class)->paid($registration, $actor);
            }
        }

        $this->receipt($invoice);

        return $invoice;
    }

    /** Carry out what the invoice was for. */
    private function fulfil(SubscriptionInvoice $invoice, Actor $actor): void
    {
        $fulfilment = (array) ($invoice->fulfilment ?? []);
        $subscription = $invoice->subscription_id ? Subscription::query()->find($invoice->subscription_id) : null;
        $reason = "Paid invoice {$invoice->number}.";

        if ($subscription !== null) {
            $this->lifecycle->event($subscription, SubscriptionEvent::PAYMENT_RECEIVED, $actor, $reason, after: [
                'invoice' => $invoice->number,
                'amount' => $invoice->total,
            ]);
        }

        match ($fulfilment['action'] ?? null) {
            'renew' => $subscription && $this->lifecycle->renew(
                $subscription,
                $actor,
                $reason,
                Plan::query()->find($fulfilment['plan_id'] ?? null),
                $fulfilment['cycle'] ?? null,
                isset($fulfilment['price']) ? (int) $fulfilment['price'] : null,
            ),

            'change_plan' => $subscription && $this->lifecycle->changePlan(
                $subscription,
                Plan::query()->findOrFail($fulfilment['plan_id']),
                (string) $fulfilment['cycle'],
                $actor,
                $reason,
                immediately: true,
            ),

            'addons' => $subscription && $this->addAddons($subscription, $fulfilment, $actor, $reason),

            // Done after the commit — see confirm().
            default => null,
        };
    }

    /** @param  array<string, mixed>  $fulfilment */
    private function addAddons(Subscription $subscription, array $fulfilment, Actor $actor, string $reason): bool
    {
        foreach ((array) ($fulfilment['modules'] ?? []) as $line) {
            $this->lifecycle->addAddon($subscription, SubscriptionItem::MODULE, (string) $line['ref'], 1, (int) $line['unit_price'], $actor, $reason);
        }

        foreach ((array) ($fulfilment['addons'] ?? []) as $line) {
            $this->lifecycle->addAddon($subscription, SubscriptionItem::ADDON, (string) $line['ref'], (int) $line['quantity'], (int) $line['unit_price'], $actor, $reason);
        }

        return true;
    }

    /** Email the receipt — after the commit, and never able to undo it. */
    private function receipt(SubscriptionInvoice $invoice): void
    {
        try {
            $organization = $invoice->organization;
            $subscription = $invoice->subscription;

            if ($organization?->email && $invoice->kind === SubscriptionInvoice::KIND_RENEWAL && $subscription) {
                EmailService::send('subscription_renewed', $organization->email, [
                    'organization_name' => (string) $organization->organization_name,
                    'plan_name' => (string) ($subscription->snapshot['plan']['name'] ?? ''),
                    'amount' => Money::format($invoice->total, $invoice->currency),
                    'invoice_number' => (string) $invoice->number,
                    'expires_on' => $subscription->refresh()->expires_at?->setTimezone($organization->timezone ?: config('app.timezone'))->format('j F Y') ?? '',
                ]);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
