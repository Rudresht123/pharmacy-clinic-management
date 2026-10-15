<?php

namespace App\Services\Subscriptions;

use App\Models\Platform\Organization;
use App\Models\Platform\Plan;
use App\Models\Platform\PublicRegistration;
use App\Models\Platform\Subscription;
use App\Models\Platform\SubscriptionInvoice;
use App\Models\Platform\UsageBalance;
use App\Support\Subscriptions\Entitlements;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Issues the platform's invoices to organizations.
 *
 * An invoice is a quote frozen: its lines and totals are copied from a
 * PricingCatalog quote at the moment of issue and never edited afterwards.
 * What paying it DOES is written on it too (`fulfilment`) — renew, upgrade,
 * add these add-ons — and carried out once, by PaymentConfirmation, when the
 * money is verified.
 *
 * Numbers are a gapless series per year — ZV-2026-000001 — taken under an
 * advisory lock so two invoices issued at once cannot share one.
 */
class InvoiceService
{
    public function __construct(
        private readonly PricingCatalog $catalog,
        private readonly BillingSettings $settings,
        private readonly SubscriptionClock $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $fulfilment
     */
    public function issue(
        Organization $organization,
        ?Subscription $subscription,
        string $kind,
        Quote $quote,
        array $fulfilment = [],
        ?CarbonInterface $periodStart = null,
        ?CarbonInterface $periodEnd = null,
        ?string $cycle = null,
        ?CarbonInterface $dueAt = null,
    ): SubscriptionInvoice {
        return DB::transaction(function () use ($organization, $subscription, $kind, $quote, $fulfilment, $periodStart, $periodEnd, $cycle, $dueAt) {
            $number = $this->nextNumber();

            return SubscriptionInvoice::query()->create([
                'number' => $number,
                'organization_id' => $organization->getKey(),
                'subscription_id' => $subscription?->id,
                'kind' => $kind,
                'status' => $quote->total > 0 ? SubscriptionInvoice::ISSUED : SubscriptionInvoice::PAID,
                'currency' => $quote->currency,
                'lines' => $quote->lines,
                'subtotal' => $quote->subtotal,
                'tax_mode' => $quote->taxMode,
                'tax_rate_bp' => $quote->taxRateBp,
                'tax' => $quote->tax,
                'total' => $quote->total,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'billing_cycle' => $cycle,
                'fulfilment' => $fulfilment === [] ? null : $fulfilment,
                'bill_to' => $this->billTo($organization),
                'issued_at' => Carbon::now(),
                'due_at' => $dueAt ?? Carbon::now()->addDays(7),
                'paid_at' => $quote->total > 0 ? null : Carbon::now(),
            ]);
        });
    }

    /**
     * A website sign-up's first invoice — issued before the organization
     * exists, so it is addressed to the registration; PublicSignup attaches
     * it to the organization once that is created.
     *
     * @param  array<string, mixed>  $fulfilment
     */
    public function issueForRegistration(PublicRegistration $registration, Quote $quote, array $fulfilment): SubscriptionInvoice
    {
        return DB::transaction(fn () => SubscriptionInvoice::query()->create([
            'number' => $this->nextNumber(),
            'public_registration_id' => $registration->id,
            'kind' => SubscriptionInvoice::KIND_NEW,
            'status' => SubscriptionInvoice::ISSUED,
            'currency' => $quote->currency,
            'lines' => $quote->lines,
            'subtotal' => $quote->subtotal,
            'tax_mode' => $quote->taxMode,
            'tax_rate_bp' => $quote->taxRateBp,
            'tax' => $quote->tax,
            'total' => $quote->total,
            'billing_cycle' => $registration->billing_cycle,
            'fulfilment' => $fulfilment,
            'bill_to' => [
                'name' => $registration->organization_name,
                'email' => $registration->email,
                'phone' => $registration->phone,
            ],
            'issued_at' => Carbon::now(),
            'due_at' => Carbon::now()->addDays(3),
        ]));
    }

    /**
     * The invoice for this subscription's next term — the open one if it was
     * already issued, so asking twice (the scheduler, then the owner pressing
     * Renew) never bills the same term twice.
     */
    public function renewalInvoice(Subscription $subscription, ?Plan $plan = null, ?string $cycle = null): SubscriptionInvoice
    {
        if ($subscription->isComplimentary() && $plan === null) {
            throw ValidationException::withMessages(['plan' => 'A complimentary subscription has nothing to renew. Choose a plan.']);
        }

        if ($plan !== null) {
            $cycle ??= Subscription::MONTHLY;
        }

        $open = SubscriptionInvoice::query()
            ->where('subscription_id', $subscription->id)
            ->where('kind', SubscriptionInvoice::KIND_RENEWAL)
            ->where('status', SubscriptionInvoice::ISSUED)
            ->latest('id')
            ->first();

        $quote = $this->catalog->quoteRenewal($subscription, $plan, $cycle);
        $targetPlan = $plan ?? $subscription->pendingPlan ?? $subscription->plan;
        $targetCycle = $cycle ?? $subscription->pending_billing_cycle ?? $subscription->billing_cycle;

        // The open one stands if it is for the same thing at the same price.
        if ($open !== null
            && ($open->fulfilment['plan_id'] ?? null) === $targetPlan->id
            && ($open->fulfilment['cycle'] ?? null) === $targetCycle
            && $open->total === $quote->total) {
            return $open;
        }

        $open?->forceFill([
            'status' => SubscriptionInvoice::VOID,
            'voided_at' => Carbon::now(),
            'void_reason' => 'Replaced by a new renewal invoice.',
        ])->save();

        $timezone = $subscription->organization->timezone ?: (string) config('app.timezone');
        $start = $subscription->expires_at !== null && $subscription->expires_at->isFuture() && ! $this->clock->isLocked($subscription)
            ? $subscription->expires_at->copy()->addSecond()
            : Carbon::now();

        return $this->issue(
            $subscription->organization,
            $subscription,
            SubscriptionInvoice::KIND_RENEWAL,
            $quote,
            ['action' => 'renew', 'plan_id' => $targetPlan->id, 'cycle' => $targetCycle, 'price' => $quote->lines[0]['amount'] ?? null],
            $start,
            $this->clock->termEnd($start, $targetCycle, $timezone),
            $targetCycle,
            // Due when the current term ends — or now, if it already has.
            $subscription->expires_at !== null && $subscription->expires_at->isFuture() ? $subscription->expires_at : Carbon::now()->addDays(3),
        );
    }

    /**
     * One invoice for a month's usage past the allowance — issued once per
     * organization per month, after the month has closed.
     */
    public function overageInvoice(Organization $organization, CarbonInterface $periodStart): ?SubscriptionInvoice
    {
        $exists = SubscriptionInvoice::query()
            ->where('organization_id', $organization->getKey())
            ->where('kind', SubscriptionInvoice::KIND_OVERAGE)
            ->where('period_start', $periodStart)
            ->exists();

        if ($exists) {
            return null;
        }

        $balances = UsageBalance::query()
            ->where('organization_id', $organization->getKey())
            ->where('period_start', $periodStart)
            ->where('overage_charge_paise', '>', 0)
            ->get();

        if ($balances->isEmpty()) {
            return null;
        }

        $lines = $balances->map(fn (UsageBalance $b) => [
            'type' => 'overage',
            'ref' => $b->quota_key,
            'description' => Entitlements::label($b->quota_key).' beyond the monthly allowance ('.Carbon::instance($b->period_start)->format('F Y').')',
            'quantity' => $b->overage,
            'unit_price' => $b->overage > 0 ? intdiv($b->overage_charge_paise, $b->overage) : 0,
            'amount' => $b->overage_charge_paise,
        ])->values()->all();

        return $this->issue(
            $organization,
            $balances->first()->subscription_id ? Subscription::query()->find($balances->first()->subscription_id) : null,
            SubscriptionInvoice::KIND_OVERAGE,
            $this->catalog->quote($lines),
            [],
            $periodStart,
            $balances->first()->period_end,
        );
    }

    public function void(SubscriptionInvoice $invoice, string $reason): SubscriptionInvoice
    {
        if ($invoice->status !== SubscriptionInvoice::ISSUED) {
            throw ValidationException::withMessages(['invoice' => 'Only an unpaid invoice can be voided.']);
        }

        $invoice->forceFill([
            'status' => SubscriptionInvoice::VOID,
            'voided_at' => Carbon::now(),
            'void_reason' => mb_substr($reason, 0, 255),
        ])->save();

        return $invoice;
    }

    /** What the next invoice will be numbered — for the settings preview. Takes nothing. */
    public function peekNumber(): string
    {
        return $this->numberAfterLast();
    }

    /** The next number in this year's series, under a lock. */
    private function nextNumber(): string
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['subscription-invoice-number']);

        return $this->numberAfterLast();
    }

    private function numberAfterLast(): string
    {
        $prefix = (string) $this->settings->get('invoice_prefix');
        // One series per financial year (Billing Settings), e.g. ZV-2026-000001.
        $series = $prefix.'-'.$this->settings->seriesYear().'-';

        $last = SubscriptionInvoice::query()
            ->where('number', 'like', $series.'%')
            ->orderByDesc('number')
            ->value('number');

        $next = $last === null ? 1 : ((int) substr($last, strlen($series))) + 1;

        return $series.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    /** @return array<string, mixed> */
    private function billTo(Organization $organization): array
    {
        return [
            'name' => $organization->legal_name ?: $organization->organization_name,
            'gstin' => $organization->gstin,
            'address' => $organization->address,
            'email' => $organization->email,
            'phone' => $organization->phone_number,
        ];
    }
}
