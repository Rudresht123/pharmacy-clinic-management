<?php

namespace App\Services\Subscriptions\Payments;

use App\Models\Platform\SubscriptionInvoice;
use App\Models\Platform\SubscriptionPayment;
use App\Services\Subscriptions\Actor;
use App\Services\Subscriptions\BillingSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Taking money for an invoice: a Razorpay checkout, the checks that prove it
 * happened, and an administrator's record of money received another way.
 *
 * Every path ends in PaymentConfirmation::confirm(), and only after a check
 * the server did itself.
 */
class PaymentService
{
    public function __construct(
        private readonly RazorpayGateway $razorpay,
        private readonly PaymentConfirmation $confirmation,
        private readonly BillingSettings $settings,
    ) {}

    /**
     * A Razorpay order for an open invoice — what the browser's checkout
     * needs, and nothing it could use to set a price.
     *
     * One order per invoice while it is open: asking again returns the same
     * order rather than a second chance to pay twice.
     *
     * @return array<string, mixed>
     */
    public function checkout(SubscriptionInvoice $invoice): array
    {
        if (! $invoice->isPayable()) {
            throw ValidationException::withMessages(['invoice' => 'This invoice is not open for payment.']);
        }

        if (! $this->razorpay->configured()) {
            throw ValidationException::withMessages(['payment' => 'Online payment is not available. Contact us to pay by bank transfer.']);
        }

        $payment = SubscriptionPayment::query()
            ->where('invoice_id', $invoice->id)
            ->where('method', SubscriptionPayment::RAZORPAY)
            ->where('status', SubscriptionPayment::CREATED)
            ->where('amount', $invoice->total)
            ->latest('id')
            ->first();

        if ($payment === null) {
            $order = $this->razorpay->createOrder($invoice->total, $invoice->currency, (string) $invoice->number, [
                'invoice' => (string) $invoice->uuid,
                'organization' => (string) $invoice->organization?->uuid,
            ]);

            $payment = SubscriptionPayment::query()->create([
                'organization_id' => $invoice->organization_id,
                'invoice_id' => $invoice->id,
                'method' => SubscriptionPayment::RAZORPAY,
                'status' => SubscriptionPayment::CREATED,
                'currency' => $invoice->currency,
                'amount' => $invoice->total,
                'gateway_order_id' => $order['id'],
                'idempotency_key' => "razorpay-order:{$order['id']}",
            ]);
        }

        return [
            'gateway' => 'razorpay',
            'key_id' => $this->razorpay->keyId(),
            'order_id' => $payment->gateway_order_id,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'invoice' => $invoice->uuid,
            'description' => "Invoice {$invoice->number}",
            'prefill' => [
                'name' => $invoice->bill_to['name'] ?? null,
                'email' => $invoice->bill_to['email'] ?? null,
                'contact' => $invoice->bill_to['phone'] ?? null,
            ],
        ];
    }

    /**
     * The browser says checkout succeeded. Believed only when the signature is
     * ours AND Razorpay itself reports a captured payment of the full amount
     * against this order.
     */
    public function verifyCheckout(string $orderId, string $paymentId, string $signature, ?int $organizationId = null): SubscriptionInvoice
    {
        $payment = SubscriptionPayment::query()->where('gateway_order_id', $orderId)->first();

        if ($payment === null || ($organizationId !== null && $payment->organization_id !== $organizationId)) {
            throw ValidationException::withMessages(['payment' => 'Unknown payment.']);
        }

        if (! $this->razorpay->checkoutSignatureValid($orderId, $paymentId, $signature)) {
            throw ValidationException::withMessages(['payment' => 'The payment could not be verified.']);
        }

        return $this->confirmFromRazorpay($payment, $paymentId, 'checkout');
    }

    /**
     * Razorpay's webhook. The raw body's signature is checked before anything
     * in it is read. `payment.captured` and `order.paid` confirm; a failure is
     * recorded. Unknown or repeated events are acknowledged and ignored.
     */
    public function handleWebhook(string $rawBody, string $signature): string
    {
        if (! $this->razorpay->webhookSignatureValid($rawBody, $signature)) {
            throw ValidationException::withMessages(['signature' => 'Invalid signature.']);
        }

        $event = json_decode($rawBody, true);
        $type = (string) ($event['event'] ?? '');
        $entity = (array) ($event['payload']['payment']['entity'] ?? []);
        $orderId = (string) ($entity['order_id'] ?? $event['payload']['order']['entity']['id'] ?? '');

        if ($orderId === '') {
            return 'ignored';
        }

        $payment = SubscriptionPayment::query()->where('gateway_order_id', $orderId)->first();

        if ($payment === null) {
            Log::info('[subscriptions] webhook for an order we did not create', ['order' => $orderId, 'event' => $type]);

            return 'ignored';
        }

        return match ($type) {
            'payment.captured', 'order.paid' => $this->confirmFromRazorpay($payment, (string) ($entity['id'] ?? ''), 'webhook') ? 'confirmed' : 'ignored',
            'payment.failed' => $this->markFailed($payment, (string) ($entity['error_description'] ?? 'Payment failed.')),
            default => 'ignored',
        };
    }

    /** Money received another way — bank transfer, UPI, cheque — recorded by an administrator. */
    public function recordManual(SubscriptionInvoice $invoice, int $amount, string $reference, Actor $actor, ?Carbon $receivedAt = null): SubscriptionInvoice
    {
        if (! $this->settings->gatewayEnabled('manual')) {
            throw ValidationException::withMessages(['method' => 'Manual payments are switched off in Billing Settings.']);
        }

        if (! $invoice->isPayable()) {
            throw ValidationException::withMessages(['invoice' => 'This invoice is not open for payment.']);
        }

        if ($amount !== $invoice->total) {
            throw ValidationException::withMessages(['amount' => 'Record the full invoice amount. Part payments are not accepted.']);
        }

        $payment = SubscriptionPayment::query()->create([
            'organization_id' => $invoice->organization_id,
            'invoice_id' => $invoice->id,
            'method' => SubscriptionPayment::MANUAL,
            'status' => SubscriptionPayment::CREATED,
            'currency' => $invoice->currency,
            'amount' => $amount,
            'reference' => mb_substr($reference, 0, 120),
            'recorded_by' => $actor->platformUserId(),
            'idempotency_key' => "manual:{$invoice->id}:".sha1($reference),
            'payload' => ['received_at' => ($receivedAt ?? Carbon::now())->toIso8601String()],
        ]);

        return $this->confirmation->confirm($payment, 'manual', [], $actor);
    }

    private function confirmFromRazorpay(SubscriptionPayment $payment, string $paymentId, string $via): SubscriptionInvoice
    {
        if ($paymentId === '') {
            throw ValidationException::withMessages(['payment' => 'No payment to confirm.']);
        }

        // Already settled by the other path — the callback and the webhook race.
        if ($payment->status === SubscriptionPayment::CAPTURED) {
            return $payment->invoice;
        }

        try {
            $remote = $this->razorpay->fetchPayment($paymentId);
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['payment' => $e->getMessage()]);
        }

        if ($remote['order_id'] !== $payment->gateway_order_id
            || $remote['amount'] !== $payment->amount
            || strtoupper($remote['currency']) !== strtoupper($payment->currency)) {
            throw ValidationException::withMessages(['payment' => 'The payment does not match this invoice.']);
        }

        if ($remote['status'] !== 'captured') {
            throw ValidationException::withMessages(['payment' => 'The payment has not been captured yet. It will be confirmed automatically when it is.']);
        }

        $payment->forceFill(['gateway_payment_id' => $remote['id']])->save();

        return $this->confirmation->confirm($payment, $via, $remote);
    }

    private function markFailed(SubscriptionPayment $payment, string $reason): string
    {
        if ($payment->status === SubscriptionPayment::CREATED) {
            $payment->forceFill([
                'status' => SubscriptionPayment::FAILED,
                'failure_reason' => mb_substr($reason, 0, 500),
            ])->save();
        }

        return 'failed';
    }
}
