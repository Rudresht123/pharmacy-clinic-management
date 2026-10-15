<?php

namespace App\Http\Controllers\Api\V1\Platform\Billing;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Platform\Plan;
use App\Services\Subscriptions\BillingSettings;
use App\Services\Subscriptions\InvoiceService;
use App\Services\Subscriptions\Payments\RazorpayGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The billing policies — tax, grace, reminders, gateways, invoice numbering,
 * the default trial plan. Every one of them a setting, none of them code.
 *
 * The Razorpay secrets are write-only: the screen says whether each is set,
 * never what it is, and an empty field on save leaves the stored one alone.
 *
 * @group Platform > Billing
 */
class BillingSettingsController extends BaseApiController
{
    public function show(BillingSettings $settings): JsonResponse
    {
        return $this->ok($this->payload($settings));
    }

    public function update(Request $request, BillingSettings $settings): JsonResponse
    {
        $request->validate([
            'default_trial_plan_id' => ['nullable', 'integer'],
            'razorpay_key_id' => ['nullable', 'string', 'max:100'],
            'razorpay_key_secret' => ['nullable', 'string', 'max:200'],
            'razorpay_webhook_secret' => ['nullable', 'string', 'max:200'],
            'seller' => ['nullable', 'array'],
            'seller.gstin' => ['nullable', 'string', 'regex:/^[0-9A-Z]{15}$/'],
        ]);

        if ($planId = $request->input('default_trial_plan_id')) {
            $plan = Plan::query()->find($planId);

            if ($plan === null || ! $plan->isPurchasable() || $plan->trial_days < 1) {
                throw ValidationException::withMessages(['default_trial_plan_id' => 'Choose a published plan that has a trial.']);
            }
        }

        $settings->save(
            $request->except(['razorpay_key_id', 'razorpay_key_secret', 'razorpay_webhook_secret']),
            $request->only(['razorpay_key_id', 'razorpay_key_secret', 'razorpay_webhook_secret']),
        );

        return $this->ok($this->payload($settings), 'Billing settings saved.');
    }

    /** @return array<string, mixed> */
    private function payload(BillingSettings $settings): array
    {
        return [
            'settings' => $settings->all(),
            'secrets' => $settings->secretsPresent(),
            'webhook_url' => url('/api/v1/webhooks/razorpay'),
            'next_invoice_number' => app(InvoiceService::class)->peekNumber(),
            'online_ready' => app(RazorpayGateway::class)->configured(),
            'defaults' => BillingSettings::DEFAULTS,
        ];
    }
}
