<?php

namespace App\Services\Subscriptions\Payments;

use App\Services\Subscriptions\BillingSettings;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Razorpay, for subscription payments — orders, and the two ways of proving
 * a payment happened.
 *
 * NOTHING THE BROWSER SAYS IS A PAYMENT. Checkout hands the browser an order
 * id, a payment id and a signature; the server checks that signature against
 * its own key secret AND asks Razorpay for the payment, and only a captured
 * payment of the right amount against the right order counts. The webhook is
 * checked the same way, against the webhook secret, over the raw body.
 *
 * Keys come from BillingSettings: the Super Admin's encrypted settings first,
 * the environment as a fallback.
 */
class RazorpayGateway
{
    public function __construct(
        private readonly BillingSettings $settings,
    ) {}

    public function configured(): bool
    {
        $keys = $this->settings->razorpay();

        return filled($keys['key_id']) && filled($keys['key_secret']) && $this->settings->gatewayEnabled('razorpay');
    }

    /** The public half, for the browser's checkout. */
    public function keyId(): ?string
    {
        return $this->settings->razorpay()['key_id'];
    }

    /**
     * Create an order for an amount in paise.
     *
     * @param  array<string, string>  $notes
     * @return array{id: string, amount: int, currency: string, status: string}
     */
    public function createOrder(int $amount, string $currency, string $receipt, array $notes = []): array
    {
        $response = $this->client()->post('/orders', [
            'amount' => $amount,
            'currency' => $currency,
            'receipt' => mb_substr($receipt, 0, 40),
            'notes' => $notes,
        ]);

        if (! $response->successful() || ! is_string($response->json('id'))) {
            throw new RuntimeException('Razorpay did not create the order: '.($response->json('error.description') ?? $response->status()));
        }

        return [
            'id' => (string) $response->json('id'),
            'amount' => (int) $response->json('amount'),
            'currency' => (string) $response->json('currency'),
            'status' => (string) $response->json('status'),
        ];
    }

    /** The checkout signature: HMAC-SHA256 of "order_id|payment_id" with the key secret. */
    public function checkoutSignatureValid(string $orderId, string $paymentId, string $signature): bool
    {
        $secret = (string) $this->settings->razorpay()['key_secret'];

        if ($secret === '' || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $orderId.'|'.$paymentId, $secret), $signature);
    }

    /** The webhook signature: HMAC-SHA256 of the raw body with the webhook secret. */
    public function webhookSignatureValid(string $rawBody, string $signature): bool
    {
        $secret = (string) $this->settings->razorpay()['webhook_secret'];

        if ($secret === '' || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature);
    }

    /**
     * The payment, as Razorpay has it — never as the browser described it.
     *
     * @return array{id: string, order_id: ?string, status: string, amount: int, currency: string, method: ?string}
     */
    public function fetchPayment(string $paymentId): array
    {
        $response = $this->client()->get('/payments/'.rawurlencode($paymentId));

        if (! $response->successful()) {
            throw new RuntimeException('Razorpay could not confirm the payment: '.($response->json('error.description') ?? $response->status()));
        }

        return [
            'id' => (string) $response->json('id'),
            'order_id' => $response->json('order_id'),
            'status' => (string) $response->json('status'),
            'amount' => (int) $response->json('amount'),
            'currency' => (string) $response->json('currency'),
            'method' => $response->json('method'),
        ];
    }

    private function client(): PendingRequest
    {
        $keys = $this->settings->razorpay();

        if (blank($keys['key_id']) || blank($keys['key_secret'])) {
            throw new RuntimeException('Razorpay is not configured. Add the keys in Billing Settings.');
        }

        return Http::baseUrl((string) config('services.razorpay.base_url'))
            ->withBasicAuth((string) $keys['key_id'], (string) $keys['key_secret'])
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('services.razorpay.timeout', 15));
    }
}
