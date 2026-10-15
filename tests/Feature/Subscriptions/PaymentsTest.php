<?php

namespace Tests\Feature\Subscriptions;

use App\Models\Platform\Organization;
use App\Models\Platform\Plan;
use App\Models\Platform\PlatformRole;
use App\Models\Platform\PlatformUser;
use App\Models\Platform\Subscription;
use App\Models\Platform\SubscriptionEvent;
use App\Models\Platform\SubscriptionInvoice;
use App\Models\Platform\SubscriptionPayment;
use App\Services\Subscriptions\Actor;
use App\Services\Subscriptions\BillingSettings;
use App\Services\Subscriptions\EntitlementService;
use App\Services\Subscriptions\InvoiceService;
use App\Services\Subscriptions\Payments\PaymentService;
use App\Services\Subscriptions\SubscriptionLifecycle;
use App\Support\Subscriptions\Entitlements;
use App\Support\Subscriptions\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Money in: invoices, Razorpay, manual payments — and that only a payment the
 * SERVER verified ever renews anything, exactly once.
 */
class PaymentsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'rzp_test_secret';

    private const WEBHOOK_SECRET = 'whsec_test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');
        Mail::fake();

        config([
            'services.razorpay.key_id' => 'rzp_test_key',
            'services.razorpay.key_secret' => self::SECRET,
            'services.razorpay.webhook_secret' => self::WEBHOOK_SECRET,
            'services.razorpay.base_url' => 'https://api.razorpay.test/v1',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-10-15 09:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function plan(int $monthly = 100000): Plan
    {
        $plan = Plan::query()->create([
            'slug' => 'growth-'.uniqid(),
            'name' => 'Growth',
            'status' => Plan::PUBLISHED,
            'monthly_price' => $monthly,
            'annual_price' => $monthly * 10,
            'grace_days' => 0,
        ]);

        foreach (Entitlements::limitKeys() as $key) {
            $plan->limits()->create(['key' => $key, 'value' => null]);
        }

        return $plan;
    }

    /** @return array{0: Organization, 1: Subscription} */
    private function lockedSubscriber(): array
    {
        $organization = Organization::factory()->active()->create();
        $subscription = app(SubscriptionLifecycle::class)->assign($organization, $this->plan(), Subscription::MONTHLY, Actor::system());

        $this->travelTo($subscription->expires_at->copy()->addDay());
        app()->forgetScopedInstances();

        $this->assertTrue(app(EntitlementService::class)->isLocked($organization));

        return [$organization, $subscription->refresh()];
    }

    private function fakeRazorpay(string $orderId, string $paymentId, int $amount, string $status = 'captured'): void
    {
        Http::fake([
            'api.razorpay.test/v1/orders' => Http::response([
                'id' => $orderId, 'amount' => $amount, 'currency' => 'INR', 'status' => 'created',
            ]),
            "api.razorpay.test/v1/payments/{$paymentId}" => Http::response([
                'id' => $paymentId, 'order_id' => $orderId, 'status' => $status, 'amount' => $amount, 'currency' => 'INR', 'method' => 'upi',
            ]),
        ]);
    }

    public function test_tax_is_added_exclusively_at_the_configured_rate_and_invoices_are_numbered(): void
    {
        [$organization, $subscription] = $this->lockedSubscriber();

        $invoice = app(InvoiceService::class)->renewalInvoice($subscription);

        $this->assertSame(100000, $invoice->subtotal);
        $this->assertSame(18000, $invoice->tax);
        $this->assertSame(118000, $invoice->total);
        $this->assertSame('exclusive', $invoice->tax_mode);
        $this->assertMatchesRegularExpression('/^ZV-\d{4}-000001$/', $invoice->number);

        // Asking again returns the same open invoice — a term is never billed twice.
        $this->assertSame($invoice->id, app(InvoiceService::class)->renewalInvoice($subscription->refresh())->id);

        // Inclusive mode: the price already contains the tax.
        $this->assertSame(['tax' => 15254, 'total' => 100000, 'net' => 84746], Money::tax(100000, 'inclusive', 1800));
    }

    public function test_a_verified_checkout_renews_and_unlocks_once_and_a_replay_changes_nothing(): void
    {
        [$organization, $subscription] = $this->lockedSubscriber();
        $invoice = app(InvoiceService::class)->renewalInvoice($subscription);

        $this->fakeRazorpay('order_1', 'pay_1', $invoice->total);
        $checkout = app(PaymentService::class)->checkout($invoice);

        $this->assertSame('order_1', $checkout['order_id']);
        $this->assertSame($invoice->total, $checkout['amount']);

        $signature = hash_hmac('sha256', 'order_1|pay_1', self::SECRET);

        app(PaymentService::class)->verifyCheckout('order_1', 'pay_1', $signature, $organization->id);
        app(PaymentService::class)->verifyCheckout('order_1', 'pay_1', $signature, $organization->id);

        app()->forgetScopedInstances();
        $this->assertFalse(app(EntitlementService::class)->isLocked($organization));
        $this->assertSame(SubscriptionInvoice::PAID, $invoice->refresh()->status);
        $this->assertNotNull($invoice->fulfilled_at);
        $this->assertSame(1, SubscriptionEvent::query()->where('type', SubscriptionEvent::RENEWED)->count());
        $this->assertSame(1, SubscriptionPayment::query()->where('status', SubscriptionPayment::CAPTURED)->count());
    }

    public function test_a_forged_signature_or_an_uncaptured_payment_changes_nothing(): void
    {
        [$organization, $subscription] = $this->lockedSubscriber();
        $invoice = app(InvoiceService::class)->renewalInvoice($subscription);

        $this->fakeRazorpay('order_2', 'pay_2', $invoice->total, status: 'authorized');
        app(PaymentService::class)->checkout($invoice);

        try {
            app(PaymentService::class)->verifyCheckout('order_2', 'pay_2', 'not-a-signature', $organization->id);
            $this->fail('A forged signature was accepted.');
        } catch (ValidationException) {
        }

        try {
            app(PaymentService::class)->verifyCheckout('order_2', 'pay_2', hash_hmac('sha256', 'order_2|pay_2', self::SECRET), $organization->id);
            $this->fail('An uncaptured payment was accepted.');
        } catch (ValidationException) {
        }

        app()->forgetScopedInstances();
        $this->assertTrue(app(EntitlementService::class)->isLocked($organization));
        $this->assertSame(SubscriptionInvoice::ISSUED, $invoice->refresh()->status);
    }

    public function test_the_webhook_is_checked_over_the_raw_body_and_confirms_once(): void
    {
        [$organization, $subscription] = $this->lockedSubscriber();
        $invoice = app(InvoiceService::class)->renewalInvoice($subscription);

        $this->fakeRazorpay('order_3', 'pay_3', $invoice->total);
        app(PaymentService::class)->checkout($invoice);

        $body = json_encode([
            'event' => 'payment.captured',
            'payload' => ['payment' => ['entity' => ['id' => 'pay_3', 'order_id' => 'order_3', 'amount' => $invoice->total]]],
        ]);

        $send = fn (string $signature) => $this->call('POST', '/api/v1/webhooks/razorpay', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => $signature,
        ], $body);

        $send('forged')->assertStatus(400);
        $this->assertSame(SubscriptionInvoice::ISSUED, $invoice->refresh()->status);

        $good = hash_hmac('sha256', $body, self::WEBHOOK_SECRET);
        $send($good)->assertOk()->assertJsonPath('data.outcome', 'confirmed');
        $send($good)->assertOk();

        $this->assertSame(SubscriptionInvoice::PAID, $invoice->refresh()->status);
        $this->assertSame(1, SubscriptionEvent::query()->where('type', SubscriptionEvent::RENEWED)->count());
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/payments/pay_3'));
    }

    public function test_a_super_admin_records_a_bank_transfer_and_the_organization_is_unlocked(): void
    {
        [$organization, $subscription] = $this->lockedSubscriber();
        $invoice = app(InvoiceService::class)->renewalInvoice($subscription);
        $admin = PlatformUser::factory()->withRole(PlatformRole::BILLING)->create();

        $this->actingAs($admin, 'platform')
            ->postJson("/api/v1/admin/billing/invoices/{$invoice->uuid}/payments", ['amount' => 100, 'reference' => 'UTR123456'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');

        $this->actingAs($admin, 'platform')
            ->postJson("/api/v1/admin/billing/invoices/{$invoice->uuid}/payments", ['amount' => $invoice->total, 'reference' => 'UTR123456'])
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');

        app()->forgetScopedInstances();
        $this->assertFalse(app(EntitlementService::class)->isLocked($organization));
        $this->assertSame('UTR123456', SubscriptionPayment::query()->sole()->reference);
    }

    public function test_manual_payments_can_be_switched_off(): void
    {
        [, $subscription] = $this->lockedSubscriber();
        $invoice = app(InvoiceService::class)->renewalInvoice($subscription);

        app(BillingSettings::class)->save(['gateways' => ['razorpay']]);

        $this->actingAs(PlatformUser::factory()->withRole(PlatformRole::SUPER_ADMIN)->create(), 'platform')
            ->postJson("/api/v1/admin/billing/invoices/{$invoice->uuid}/payments", ['amount' => $invoice->total, 'reference' => 'UTR1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('method');
    }
}
