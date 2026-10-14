<?php

namespace App\Http\Controllers\Api\V1\Website;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Platform\OrganizationType;
use App\Models\Platform\PublicRegistration;
use App\Models\Platform\Subscription;
use App\Services\Subscriptions\Payments\PaymentService;
use App\Services\Subscriptions\PublicSignup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A clinic signing up from the website: register, pay (unless it is a
 * trial), and poll until the workspace is ready.
 *
 * Idempotent: every registration carries the website's `Idempotency-Key`
 * header, and repeating a request with the same key returns the same
 * registration. Prices are never taken from the request — the server quotes
 * the plan, cycle and add-ons it is given.
 *
 * A paid sign-up becomes an organization only after the payment is verified
 * server-side (the checkout signature plus Razorpay's own record, or the
 * webhook). The browser saying "paid" changes nothing.
 *
 * @group Website › Sign-up
 *
 * @unauthenticated
 */
class SignupController extends BaseApiController
{
    public function __construct(
        private readonly PublicSignup $signup,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));

        if ($key === '' || strlen($key) > 191) {
            throw ValidationException::withMessages(['idempotency_key' => 'Send an Idempotency-Key header (a unique id for this sign-up).']);
        }

        $data = $request->validate([
            'plan' => ['required', 'string', 'max:60'],
            'billing_cycle' => ['required', Rule::in([Subscription::MONTHLY, Subscription::ANNUAL])],
            'start_trial' => ['boolean'],
            'organization_name' => ['required', 'string', 'max:191'],
            'contact_name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:30'],
            'subdomain' => ['required', 'string', 'max:63'],
            'organization_type_id' => ['nullable', 'integer', Rule::exists(OrganizationType::class, 'id')->where('is_active', true)],
            'addons' => ['array'],
            'addons.*' => ['integer', 'min:0', 'max:1000'],
            'modules' => ['array'],
            'modules.*' => ['string', 'max:60'],
            // A honeypot, as on the enquiry form: people never see it.
            'website' => ['prohibited'],
        ]);

        $registration = $this->signup->register($data, $key, $request->ip());

        return $this->created($this->signup->status($registration), $registration->start_trial
            ? 'Your trial is being set up.'
            : 'Registered. Continue to payment.');
    }

    public function status(string $reference): JsonResponse
    {
        return $this->ok($this->signup->status($this->registration($reference)));
    }

    /** The invoice and Razorpay order for a registration waiting on payment. */
    public function checkout(Request $request, PaymentService $payments): JsonResponse
    {
        $data = $request->validate(['reference' => ['required', 'string', 'size:26']]);

        return $this->ok($this->signup->checkout($this->registration($data['reference']), $payments));
    }

    /** The browser's checkout callback — verified against Razorpay before anything happens. */
    public function verify(Request $request, PaymentService $payments): JsonResponse
    {
        $data = $request->validate([
            'reference' => ['required', 'string', 'size:26'],
            'razorpay_order_id' => ['required', 'string', 'max:80'],
            'razorpay_payment_id' => ['required', 'string', 'max:80'],
            'razorpay_signature' => ['required', 'string', 'max:200'],
        ]);

        $registration = $this->registration($data['reference']);

        $invoice = $payments->verifyCheckout($data['razorpay_order_id'], $data['razorpay_payment_id'], $data['razorpay_signature']);

        if ($invoice->public_registration_id !== $registration->id) {
            throw ValidationException::withMessages(['reference' => 'That payment is for another registration.']);
        }

        return $this->ok($this->signup->status($registration->refresh()), 'Payment received. Setting up your workspace.');
    }

    private function registration(string $reference): PublicRegistration
    {
        return PublicRegistration::query()->where('reference', $reference)->firstOrFail();
    }
}
