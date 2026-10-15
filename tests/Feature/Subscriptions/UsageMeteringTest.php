<?php

namespace Tests\Feature\Subscriptions;

use App\Models\Platform\Organization;
use App\Models\Platform\Plan;
use App\Models\Platform\Subscription;
use App\Models\Platform\UsageRate;
use App\Models\Platform\UsageRecord;
use App\Services\Subscriptions\Actor;
use App\Services\Subscriptions\SubscriptionLifecycle;
use App\Services\Subscriptions\UsageBalances;
use App\Services\Subscriptions\UsageMeter;
use App\Support\Subscriptions\Entitlements;
use App\Support\Subscriptions\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The usage ledger: one charge per real event, the allowance and the charge
 * moving together, and SMS dormant until it has a provider.
 */
class UsageMeteringTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');

        $plan = Plan::query()->create([
            'slug' => 'starter',
            'name' => 'Starter',
            'status' => Plan::PUBLISHED,
            'monthly_price' => 99900,
            'annual_price' => 999000,
            'features' => [Entitlements::VIDEO_CONSULTATION],
        ]);

        foreach (Entitlements::limitKeys() as $key) {
            $plan->limits()->create(['key' => $key, 'value' => match ($key) {
                Entitlements::WHATSAPP_MESSAGES => 2,
                Entitlements::VIDEO_MINUTES => 30,
                default => 100,
            }]);
        }

        $this->organization = Organization::factory()->active()->create();
        app(SubscriptionLifecycle::class)->assign($this->organization, $plan, Subscription::MONTHLY, Actor::system());
    }

    private function meter(): UsageMeter
    {
        app()->forgetScopedInstances();

        return app(UsageMeter::class);
    }

    private function balance(string $quota)
    {
        app()->forgetScopedInstances();

        return app(UsageBalances::class)->current($this->organization, $quota);
    }

    private function rate(string $policy, int $priceE4 = 1150, int $costE4 = 800): UsageRate
    {
        return UsageRate::query()->create([
            'channel' => 'whatsapp',
            'provider' => '*',
            'destination' => '*',
            'billing_unit' => 'message',
            'provider_cost_e4' => $costE4,
            'price_e4' => $priceE4,
            'overage_policy' => $policy,
            'status' => UsageRate::PUBLISHED,
            'effective_from' => now()->subDay(),
        ]);
    }

    public function test_a_retried_job_or_a_repeated_receipt_is_charged_once(): void
    {
        foreach (range(1, 4) as $ignored) {
            $this->meter()->record($this->organization, 'whatsapp', 'message_log', 41, 1);
        }

        $this->assertSame(1, UsageRecord::query()->count());
        $this->assertSame(1, $this->balance(Entitlements::WHATSAPP_MESSAGES)->used);
    }

    public function test_an_exhausted_allowance_blocks_unless_the_rate_allows_overage(): void
    {
        $this->meter()->record($this->organization, 'whatsapp', 'message_log', 1, 1);
        $this->meter()->record($this->organization, 'whatsapp', 'message_log', 2, 1);

        $this->assertFalse($this->meter()->authorize($this->organization, 'whatsapp')->allowed);
        $this->assertSame('quota_exhausted', $this->meter()->authorize($this->organization, 'whatsapp')->reason);

        $this->rate(UsageRate::ALLOW, priceE4: 1150, costE4: 800);
        $this->assertTrue($this->meter()->authorize($this->organization, 'whatsapp')->allowed);

        $record = $this->meter()->record($this->organization, 'whatsapp', 'message_log', 3, 1);

        $this->assertSame(1, $record->overage);
        $this->assertSame(1150, $record->price_e4);
        $this->assertSame(12, $record->charge_paise, '₹0.1150 is 11.5 paise, rounded half-up to 12');
        $this->assertSame(800, $record->provider_cost_e4, 'the provider cost is kept apart from the price');
        $this->assertSame(1, $this->balance(Entitlements::WHATSAPP_MESSAGES)->overage);
    }

    public function test_a_failed_send_is_reversed_once_and_the_units_come_back(): void
    {
        $this->meter()->record($this->organization, 'whatsapp', 'message_log', 7, 1);
        $this->assertSame(1, $this->balance(Entitlements::WHATSAPP_MESSAGES)->used);

        $this->meter()->reverse($this->organization, 'whatsapp', 'message_log', 7, 'Undeliverable');
        $this->meter()->reverse($this->organization, 'whatsapp', 'message_log', 7, 'Undeliverable');

        $this->assertSame(1, UsageRecord::query()->where('status', UsageRecord::REVERSED)->count());
        $this->assertSame(1, UsageRecord::query()->where('status', UsageRecord::RECORDED)->count(), 'the original is never deleted');
        $this->assertSame(0, $this->balance(Entitlements::WHATSAPP_MESSAGES)->used);
    }

    public function test_sms_is_dormant_until_a_provider_exists(): void
    {
        $this->assertFalse(UsageMeter::isMetered('sms'));
        $this->assertNull($this->meter()->record($this->organization, 'sms', 'message_log', 9, 1));
        $this->assertTrue($this->meter()->authorize($this->organization, 'sms')->allowed);
        $this->assertSame(0, UsageRecord::query()->count());
    }

    public function test_video_minutes_need_the_feature_and_keep_the_rate_they_were_charged_at(): void
    {
        $this->assertTrue($this->meter()->authorize($this->organization, 'video')->allowed);

        $first = $this->meter()->record($this->organization, 'video', 'video_call', 5, 12);
        $this->assertSame(12, $first->from_quota);

        // A new rate later in the month does not reach back.
        UsageRate::query()->create([
            'channel' => 'video', 'billing_unit' => 'minute', 'provider_cost_e4' => 0, 'price_e4' => 50000,
            'overage_policy' => UsageRate::ALLOW, 'status' => UsageRate::PUBLISHED, 'effective_from' => now()->subMinute(),
        ]);

        $second = $this->meter()->record($this->organization, 'video', 'video_call', 6, 25);

        $this->assertNull($first->refresh()->usage_rate_id);
        $this->assertSame(18, $second->from_quota);
        $this->assertSame(7, $second->overage);
        $this->assertSame(Money::e4ToPaise(7 * 50000), $second->charge_paise);
    }
}
