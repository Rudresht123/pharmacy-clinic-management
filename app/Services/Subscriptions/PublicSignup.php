<?php

namespace App\Services\Subscriptions;

use App\Models\Platform\Organization;
use App\Models\Platform\OrganizationType;
use App\Models\Platform\Plan;
use App\Models\Platform\PublicRegistration;
use App\Models\Platform\Subscription;
use App\Models\Platform\SubscriptionInvoice;
use App\Services\Platform\OrganizationProvisioningService;
use App\Services\Subscriptions\Payments\PaymentService;
use App\Support\Platform\OrganizationCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * A clinic signing itself up from the marketing website.
 *
 * Two roads, both ending in the EXISTING onboarding —
 * OrganizationProvisioningService::provision(), the same call the Super
 * Admin's "New organization" makes. There is no second way to create a tenant.
 *
 *   Trial   provisioned straight away, on the plan's trial.
 *   Paid    a registration waits at `pending_payment` with an invoice; the
 *           organization is provisioned only when PaymentConfirmation has
 *           verified the money. Nobody gets a tenant database for an unpaid
 *           checkout.
 *
 * Idempotent on the website's own key: a double-clicked button or a retried
 * request finds the first registration and answers with it.
 *
 * Reads and writes the master database only. Provisioning itself runs on the
 * queue, never inside the request.
 */
class PublicSignup
{
    public function __construct(
        private readonly PricingCatalog $catalog,
        private readonly InvoiceService $invoices,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated website input
     */
    public function register(array $data, string $idempotencyKey, ?string $ip = null): PublicRegistration
    {
        $existing = PublicRegistration::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            return $existing;
        }

        $plan = $this->catalog->websitePlan((string) $data['plan']);

        if ($plan === null) {
            throw ValidationException::withMessages(['plan' => 'That plan is not available.']);
        }

        $subdomain = strtolower(trim((string) $data['subdomain']));
        $this->assertSubdomainFree($subdomain);

        $cycle = (string) $data['billing_cycle'];
        $quote = $this->catalog->quoteSubscription($plan, $cycle, (array) ($data['addons'] ?? []), (array) ($data['modules'] ?? []));
        $trial = (bool) ($data['start_trial'] ?? false);

        if ($trial && $plan->trial_days < 1) {
            throw ValidationException::withMessages(['start_trial' => "The {$plan->name} plan has no free trial."]);
        }

        $registration = PublicRegistration::query()->create([
            'idempotency_key' => $idempotencyKey,
            'status' => PublicRegistration::PENDING_PAYMENT,
            'plan_id' => $plan->id,
            'billing_cycle' => $cycle,
            'start_trial' => $trial,
            'addons' => ['addons' => $data['addons'] ?? [], 'modules' => $data['modules'] ?? []],
            'organization_name' => $data['organization_name'],
            'contact_name' => $data['contact_name'],
            'email' => strtolower((string) $data['email']),
            'phone' => $data['phone'] ?? null,
            'subdomain' => $subdomain,
            'organization_type_id' => $data['organization_type_id'] ?? null,
            'quote' => $quote->toArray(),
            'ip_address' => $ip,
        ]);

        // A trial, or a plan that costs nothing: no money to wait for.
        if ($trial || $quote->total === 0) {
            $this->provision($registration, trial: $trial);
        }

        return $registration->refresh();
    }

    /**
     * The invoice and Razorpay order for a registration waiting on payment.
     * The same invoice every time it is asked for.
     *
     * @return array<string, mixed>
     */
    public function checkout(PublicRegistration $registration, PaymentService $payments): array
    {
        if ($registration->status !== PublicRegistration::PENDING_PAYMENT) {
            throw ValidationException::withMessages(['reference' => 'This registration is not waiting for payment.']);
        }

        $invoice = SubscriptionInvoice::query()
            ->where('public_registration_id', $registration->id)
            ->where('status', SubscriptionInvoice::ISSUED)
            ->first();

        if ($invoice === null) {
            $quote = $this->catalog->quoteSubscription(
                $registration->plan,
                $registration->billing_cycle,
                (array) ($registration->addons['addons'] ?? []),
                (array) ($registration->addons['modules'] ?? []),
            );

            $invoice = $this->invoices->issueForRegistration($registration, $quote, [
                'action' => 'activate_registration',
                'registration_id' => $registration->id,
            ]);
        }

        return ['invoice' => $invoice->uuid, 'total' => $invoice->total, 'checkout' => $payments->checkout($invoice)];
    }

    /** Payment verified: create the organization, on a paid term. */
    public function paid(PublicRegistration $registration, ?Actor $actor = null): void
    {
        if (in_array($registration->status, [PublicRegistration::PROVISIONING, PublicRegistration::ACTIVE], true)) {
            return;
        }

        $registration->forceFill(['status' => PublicRegistration::PAID])->save();

        $this->provision($registration, trial: false);
    }

    /** What the website shows while it polls. @return array<string, mixed> */
    public function status(PublicRegistration $registration): array
    {
        $organization = $registration->organization;

        if ($organization !== null && $registration->status === PublicRegistration::PROVISIONING && $organization->isActive()) {
            $registration->forceFill(['status' => PublicRegistration::ACTIVE])->save();
        }

        if ($organization !== null && $organization->status === Organization::FAILED && $registration->status === PublicRegistration::PROVISIONING) {
            $registration->forceFill(['status' => PublicRegistration::FAILED, 'failure_reason' => 'Setting up the workspace did not finish. Our team has been told.'])->save();
        }

        $domain = config('organization.main_domain');
        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';

        return [
            'reference' => $registration->reference,
            'status' => $registration->status,
            'plan' => $registration->plan?->name,
            'trial' => $registration->start_trial,
            'total' => $registration->quote['total'] ?? null,
            'currency' => $registration->quote['currency'] ?? 'INR',
            'workspace' => $registration->status === PublicRegistration::ACTIVE && $organization !== null ? [
                'subdomain' => $organization->subdomain,
                'login_url' => $domain ? "{$scheme}://{$organization->subdomain}.{$domain}/login" : url('/login'),
            ] : null,
            'message' => match ($registration->status) {
                PublicRegistration::PENDING_PAYMENT => 'Waiting for payment.',
                PublicRegistration::PAID, PublicRegistration::PROVISIONING => 'Setting up your workspace. We will email you when it is ready.',
                PublicRegistration::ACTIVE => 'Your workspace is ready. Check your email to set your password.',
                PublicRegistration::FAILED => $registration->failure_reason ?? 'Something went wrong. Our team has been told.',
                default => null,
            },
        ];
    }

    /**
     * Hand the registration to the existing onboarding. The subscription is
     * created with the organization, in the same transaction (see
     * SubscriptionOnboarding): a trial, or a paid term.
     */
    private function provision(PublicRegistration $registration, bool $trial): void
    {
        try {
            $organization = app(OrganizationProvisioningService::class)->provision([
                'organization_name' => $registration->organization_name,
                'organization_code' => OrganizationCode::generate(),
                'organization_type_id' => $registration->organization_type_id ?? $this->defaultType(),
                'subdomain' => $registration->subdomain,
                'email' => $registration->email,
                'contact_person_name' => $registration->contact_name,
                'phone_number' => $registration->phone,
                'slug' => Organization::generateSlug($registration->organization_name),
                'tenant_key' => $key = Organization::generateTenantKey($registration->organization_name),
                'database_name' => Organization::generateDatabaseName($key),
                'subscription' => [
                    'plan_id' => $registration->plan_id,
                    'billing_cycle' => $registration->billing_cycle,
                    'trial' => $trial,
                    'payment_status' => $trial ? Subscription::PAYMENT_NONE : Subscription::PAYMENT_PAID,
                ],
            ]);

            DB::transaction(function () use ($registration, $organization) {
                $registration->forceFill([
                    'status' => PublicRegistration::PROVISIONING,
                    'organization_id' => $organization->id,
                ])->save();

                // The first invoice was issued before the organization existed.
                SubscriptionInvoice::query()
                    ->where('public_registration_id', $registration->id)
                    ->whereNull('organization_id')
                    ->update([
                        'organization_id' => $organization->id,
                        'subscription_id' => Subscription::query()->where('organization_id', $organization->id)->where('is_current', true)->value('id'),
                    ]);
            });
        } catch (Throwable $e) {
            report($e);

            $registration->forceFill([
                'status' => PublicRegistration::FAILED,
                'failure_reason' => 'We could not set up the workspace automatically. Our team will contact you.',
            ])->save();
        }
    }

    private function assertSubdomainFree(string $subdomain): void
    {
        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{1,61}[a-z0-9])$/', $subdomain)) {
            throw ValidationException::withMessages(['subdomain' => 'Use 3–63 lowercase letters, digits and dashes.']);
        }

        $taken = Organization::withTrashed()->where('subdomain', $subdomain)->exists()
            || PublicRegistration::query()
                ->where('subdomain', $subdomain)
                ->whereIn('status', [PublicRegistration::PENDING_PAYMENT, PublicRegistration::PAID, PublicRegistration::PROVISIONING, PublicRegistration::ACTIVE])
                ->exists();

        if ($taken || in_array($subdomain, ['www', 'admin', 'api', 'app', 'mail', 'billing', 'support'], true)) {
            throw ValidationException::withMessages(['subdomain' => 'That address is taken. Choose another.']);
        }
    }

    private function defaultType(): ?int
    {
        return OrganizationType::query()->where('is_active', true)->orderBy('id')->value('id');
    }
}
