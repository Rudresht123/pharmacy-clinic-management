<?php

namespace App\Http\Controllers\Api\V1\Webhooks;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Services\Subscriptions\Payments\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Razorpay telling us a payment was captured, an order paid, or a payment
 * failed.
 *
 * No session, no user — the signature IS the authentication. It is checked
 * over the raw body, byte for byte, before a single field of the payload is
 * read. A bad signature answers 400 and changes nothing. A repeated delivery
 * (Razorpay retries until it sees a 2xx) confirms nothing twice: payments and
 * invoices are idempotent on the gateway's ids.
 *
 * @group Webhooks
 *
 * @unauthenticated
 */
class RazorpayWebhookController extends BaseApiController
{
    public function __invoke(Request $request, PaymentService $payments): JsonResponse
    {
        try {
            $outcome = $payments->handleWebhook(
                $request->getContent(),
                (string) $request->header('X-Razorpay-Signature', ''),
            );
        } catch (ValidationException $e) {
            if (array_key_exists('signature', $e->errors())) {
                return $this->fail('Invalid signature.', 400);
            }

            throw $e;
        }

        return $this->ok(['outcome' => $outcome]);
    }
}
