<?php

namespace Tests\Feature\Subscriptions;

use App\Models\Platform\Module;
use App\Models\Platform\Organization;
use App\Models\Platform\Plan;
use App\Models\Platform\PlatformAuditLog;
use App\Models\Platform\PlatformRole;
use App\Models\Platform\PlatformUser;
use App\Models\Platform\Subscription;
use App\Models\Platform\SubscriptionEvent;
use App\Services\Subscriptions\Actor;
use App\Services\Subscriptions\SubscriptionLifecycle;
use App\Support\Subscriptions\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Super Admin's billing area: who may read, who may change, and that
 * every change says who and why.
 */
class AdminBillingApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');
    }

    private function admin(string $role = PlatformRole::BILLING): PlatformUser
    {
        return PlatformUser::factory()->withRole($role)->create();
    }

    /** @return array<string, mixed> */
    private function planPayload(array $overrides = []): array
    {
        return [
            'name' => 'Growth',
            'slug' => 'growth',
            'monthly_price' => 149900,
            'annual_price' => 1499000,
            'trial_days' => 14,
            'grace_days' => 3,
            'is_public' => true,
            'features' => [Entitlements::VIDEO_CONSULTATION],
            'limits' => array_fill_keys(Entitlements::limitKeys(), 10),
            'modules' => ['appointments'],
            ...$overrides,
        ];
    }

    public function test_support_can_read_billing_but_only_the_billing_role_can_change_it(): void
    {
        $this->actingAs($this->admin(PlatformRole::SUPPORT), 'platform')->getJson('/api/v1/admin/billing/plans')->assertOk();
        $this->actingAs($this->admin(PlatformRole::SUPPORT), 'platform')->postJson('/api/v1/admin/billing/plans', $this->planPayload())->assertForbidden();
        $this->actingAs($this->admin(PlatformRole::CATALOG), 'platform')->getJson('/api/v1/admin/billing/overview')->assertForbidden();

        $this->actingAs($this->admin(PlatformRole::BILLING), 'platform')->postJson('/api/v1/admin/billing/plans', $this->planPayload())->assertCreated();
    }

    public function test_a_plan_is_drafted_published_and_its_changes_are_audited(): void
    {
        $admin = $this->admin();

        $uuid = $this->actingAs($admin, 'platform')->postJson('/api/v1/admin/billing/plans', $this->planPayload())
            ->assertCreated()
            ->assertJsonPath('data.status', Plan::DRAFT)
            ->assertJsonPath('data.limits.doctors', 10)
            ->json('data.uuid');

        // Every limit must be set — blank is unlimited, missing is a mistake.
        $this->actingAs($admin, 'platform')->putJson("/api/v1/admin/billing/plans/{$uuid}", $this->planPayload(['limits' => ['doctors' => 5]]))
            ->assertStatus(422)->assertJsonValidationErrors('limits');

        $this->actingAs($admin, 'platform')->postJson("/api/v1/admin/billing/plans/{$uuid}/status", ['status' => Plan::PUBLISHED])
            ->assertOk()->assertJsonPath('data.status', Plan::PUBLISHED);

        $this->actingAs($admin, 'platform')->putJson("/api/v1/admin/billing/plans/{$uuid}", $this->planPayload(['monthly_price' => 179900, 'modules' => ['appointments', 'laboratory']]))
            ->assertOk();

        $plan = Plan::query()->where('uuid', $uuid)->sole();

        $this->assertTrue(PlatformAuditLog::query()
            ->where('entity_type', 'Plan')->where('entity_id', $plan->id)
            ->where('platform_user_id', $admin->id)
            ->get()
            ->contains(fn ($log) => ($log->after['monthly_price'] ?? null) === 179900));

        $this->assertTrue(PlatformAuditLog::query()->where('entity_type', 'Plan')->get()
            ->contains(fn ($log) => in_array('laboratory', (array) ($log->after['modules'] ?? []), true)));

        // A published plan's address is what the website links to.
        $this->actingAs($admin, 'platform')->putJson("/api/v1/admin/billing/plans/{$uuid}", $this->planPayload(['slug' => 'renamed']))
            ->assertStatus(422)->assertJsonValidationErrors('slug');

        // Complimentary is the platform's.
        $complimentary = Plan::query()->where('slug', Plan::COMPLIMENTARY)->sole();
        $this->actingAs($admin, 'platform')->putJson("/api/v1/admin/billing/plans/{$complimentary->uuid}", $this->planPayload(['slug' => 'complimentary']))
            ->assertStatus(422);
    }

    public function test_every_subscription_lever_needs_a_reason_and_names_the_administrator(): void
    {
        $admin = $this->admin();
        $organization = Organization::factory()->active()->create();

        $plan = Plan::query()->create(['slug' => 'pro', 'name' => 'Pro', 'status' => Plan::PUBLISHED, 'monthly_price' => 299900, 'annual_price' => 2999000]);
        foreach (Entitlements::limitKeys() as $key) {
            $plan->limits()->create(['key' => $key, 'value' => 5]);
        }

        $base = "/api/v1/admin/billing/organizations/{$organization->uuid}/subscription";

        $this->actingAs($admin, 'platform')->postJson("{$base}/assign", ['plan_id' => $plan->id, 'billing_cycle' => 'monthly'])
            ->assertStatus(422)->assertJsonValidationErrors('reason');

        $this->actingAs($admin, 'platform')->postJson("{$base}/assign", ['plan_id' => $plan->id, 'billing_cycle' => 'monthly', 'reason' => 'Signed the Pro contract.'])
            ->assertOk()->assertJsonPath('data.plan.name', 'Pro');

        $this->actingAs($admin, 'platform')->postJson("{$base}/limits", ['key' => 'doctors', 'value' => 9, 'reason' => 'Temporary locum cover.'])->assertOk();
        $this->actingAs($admin, 'platform')->postJson("{$base}/credits", ['quota' => 'whatsapp_messages', 'amount' => 200, 'reason' => 'Goodwill after an outage.'])->assertOk();
        $this->actingAs($admin, 'platform')->postJson("{$base}/extend", ['days' => 10, 'reason' => 'Waiting on their purchase order.'])->assertOk();
        $this->actingAs($admin, 'platform')->postJson("{$base}/suspend", ['reason' => 'Cheque bounced.'])->assertOk()->assertJsonPath('data.locked', true);
        $this->actingAs($admin, 'platform')->postJson("{$base}/unlock", ['reason' => 'Cheque cleared.'])->assertOk()->assertJsonPath('data.locked', false);

        $events = SubscriptionEvent::query()->where('organization_id', $organization->id)->where('actor_type', 'platform')->get();

        foreach ([SubscriptionEvent::CREATED, SubscriptionEvent::LIMIT_OVERRIDDEN, SubscriptionEvent::CREDITS_GRANTED, SubscriptionEvent::EXTENDED, SubscriptionEvent::SUSPENDED, SubscriptionEvent::UNLOCKED] as $type) {
            $event = $events->firstWhere('type', $type);

            $this->assertNotNull($event, "no {$type} event");
            $this->assertSame($admin->id, (int) $event->actor_id);
            $this->assertNotEmpty($event->reason);
        }

        $show = $this->actingAs($this->admin(PlatformRole::SUPPORT), 'platform')->getJson($base)->assertOk();
        $override = collect($show->json('data.items'))->firstWhere('type', 'override');
        $this->assertSame('doctors', $override['target']);
        $this->assertSame(9, $override['grants']);
        $this->assertSame(200, $show->json('data.usage.whatsapp_messages.granted'));
    }

    public function test_a_module_override_on_a_real_plan_needs_a_reason(): void
    {
        $admin = $this->admin(PlatformRole::SUPER_ADMIN);
        $organization = Organization::factory()->active()->create();
        $url = "/api/v1/admin/organizations/{$organization->uuid}/modules";
        $body = ['modules' => [['key' => 'appointments', 'is_enabled' => true]]];

        // Complimentary: modules are still sold this way, reason optional.
        app(SubscriptionLifecycle::class)->assign($organization, Plan::query()->where('slug', 'complimentary')->sole(), Subscription::COMPLIMENTARY, Actor::system());
        $this->actingAs($admin, 'platform')->putJson($url, $body)->assertOk();

        $plan = Plan::query()->create(['slug' => 'basic', 'name' => 'Basic', 'status' => Plan::PUBLISHED, 'monthly_price' => 1000, 'annual_price' => 10000]);
        app(SubscriptionLifecycle::class)->assign($organization, $plan, Subscription::MONTHLY, Actor::system());
        app()->forgetScopedInstances();

        $this->actingAs($admin, 'platform')->putJson($url, $body)->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->actingAs($admin, 'platform')->putJson($url, [...$body, 'reason' => 'Pilot of the OPD module.'])
            ->assertOk();

        $this->assertSame('Pilot of the OPD module.', $organization->moduleEntitlements()->where('module_id', Module::query()->where('key', 'appointments')->value('id'))->value('reason'));
    }

    public function test_the_billing_screens_figures_come_from_the_records(): void
    {
        $admin = $this->admin();
        $organization = Organization::factory()->active()->create();
        $plan = Plan::query()->create(['slug' => 'pro', 'name' => 'Pro', 'status' => Plan::PUBLISHED, 'monthly_price' => 100000, 'annual_price' => 1000000]);
        $subscription = app(SubscriptionLifecycle::class)->assign($organization, $plan, Subscription::MONTHLY, Actor::system());

        $invoice = app(\App\Services\Subscriptions\InvoiceService::class)->renewalInvoice($subscription);
        app(\App\Services\Subscriptions\Payments\PaymentService::class)->recordManual($invoice, $invoice->total, 'UTR-1', Actor::system());

        $this->actingAs($admin, 'platform')->getJson('/api/v1/admin/billing/overview')
            ->assertOk()
            ->assertJsonPath('data.kpis.total_revenue.value', 118000)
            ->assertJsonPath('data.kpis.total_invoices.value', 1)
            ->assertJsonPath('data.recent_invoices.0.status', 'paid')
            ->assertJsonPath('data.revenue_by_plan.0.label', 'Pro')
            ->assertJsonCount(6, 'data.revenue_trend');

        $this->actingAs($admin, 'platform')->getJson('/api/v1/admin/billing/subscriptions/insights')
            ->assertOk()->assertJsonCount(12, 'data.growth')->assertJsonPath('data.plan_distribution.0.label', 'Pro');

        $this->actingAs($admin, 'platform')->getJson('/api/v1/admin/billing/plans/summary')
            ->assertOk()->assertJsonPath('data.monthly_revenue', 100000)->assertJsonPath('data.most_popular_plan_id', $plan->id);

        $this->actingAs($admin, 'platform')->getJson('/api/v1/admin/billing/pricing/insights')
            ->assertOk()->assertJsonCount(6, 'data.trend')->assertJsonPath('data.revenue_by_module.0.label', 'Plan subscriptions');

        $this->actingAs($admin, 'platform')->getJson('/api/v1/admin/billing/invoices/insights')
            ->assertOk()
            ->assertJsonPath('data.tiles.paid_amount', 118000)
            ->assertJsonPath('data.payment_methods.0.label', 'Bank transfer / manual');

        $this->actingAs($admin, 'platform')->getJson('/api/v1/admin/billing/invoices?status=paid&search=ZV')
            ->assertOk()->assertJsonPath('data.0.display_status', 'paid')->assertJsonPath('data.0.payment_mode', 'Bank transfer');
    }

    public function test_the_overview_counts_from_real_records(): void
    {
        $organization = Organization::factory()->active()->create();
        $plan = Plan::query()->create(['slug' => 'pro', 'name' => 'Pro', 'status' => Plan::PUBLISHED, 'monthly_price' => 300000, 'annual_price' => 3600000]);
        app(SubscriptionLifecycle::class)->assign($organization, $plan, Subscription::ANNUAL, Actor::system());

        $this->actingAs($this->admin(), 'platform')->getJson('/api/v1/admin/billing/overview')
            ->assertOk()
            ->assertJsonPath('data.kpis.active_subscriptions.value', 1)
            ->assertJsonPath('data.attention.locked', 0)
            ->assertJsonPath('data.attention.without_subscription', 0);

        // An annual term is worth a twelfth of its price each month.
        $this->actingAs($this->admin(), 'platform')->getJson('/api/v1/admin/billing/plans/summary')
            ->assertOk()
            ->assertJsonPath('data.monthly_revenue', 300000);
    }
}
