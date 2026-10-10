<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Resources\Tenant\InvoiceResource;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\InvoicePayment;
use App\Services\Billing\BillingConflict;
use App\Services\Billing\BillingService;
use App\Services\Tenancy\TenantBranchAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Money against an invoice.
 *
 * Recording a payment is `billing.collect_payment`; refunding is
 * `billing.refund`. Both go through BillingService, which is what recomputes
 * the invoice's paid_amount and status columns from the payment rows (never
 * from the request) and enforces the organisation's payment rules.
 *
 * BOTH ARE IDEMPOTENT. Each carries an Idempotency-Key header, the same
 * contract the pharmacy's sales use: a double-tapped "Record payment" — or a
 * request retried on a bad connection — answers 200 with the bill as the
 * first one left it, and records nothing a second time.
 *
 * @group Tenant > Billing
 */
class InvoicePaymentController extends BaseApiController
{
    public function __construct(
        private readonly BillingService $billing,
        private readonly TenantBranchAccess $branches,
    ) {}

    /**
     * Record a payment against an invoice.
     *
     * One tender (`method`, `amount`) or several (`tenders[]`) — a bill
     * settled ₹300 cash and ₹200 UPI is one collection, which is what lets a
     * clinic that takes bills in full still take that one.
     */
    public function store(Request $request, Invoice $invoice): JsonResponse
    {
        $this->assertReachable($invoice);

        $request->merge(['idempotency_key' => $request->header('Idempotency-Key')]);

        $tender = [
            'string', Rule::in(InvoicePayment::METHODS),
        ];

        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:100'],

            'tenders' => ['sometimes', 'array', 'min:1', 'max:5'],
            'tenders.*.method' => ['required', ...$tender],
            'tenders.*.amount' => ['required', 'numeric', 'min:0.01', 'max:9999999.99'],
            'tenders.*.reference' => ['nullable', 'string', 'max:60'],

            'method' => ['required_without:tenders', ...$tender],
            'amount' => ['required_without:tenders', 'numeric', 'min:0.01', 'max:9999999.99'],
            'reference' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'idempotency_key.required' => 'This payment was sent without an Idempotency-Key header.',
        ]);

        $tenders = $validated['tenders'] ?? [[
            'method' => $validated['method'],
            'amount' => $validated['amount'],
            'reference' => $validated['reference'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]];

        $key = $validated['idempotency_key'];
        $seen = InvoicePayment::query()->where('idempotency_key', $key)->exists();

        try {
            $updated = $this->billing->collect($invoice, $tenders, $key);
        } catch (BillingConflict $e) {
            return $this->fail($e->getMessage(), 422);
        }

        $resource = InvoiceResource::make($updated);

        return $seen
            ? $this->ok($resource, 'That payment was already recorded')
            : $this->created($resource, 'Payment recorded');
    }

    /** Refund an already-recorded payment. */
    public function refund(Request $request, Invoice $invoice, InvoicePayment $payment): JsonResponse
    {
        $this->assertReachable($invoice);

        $request->merge(['idempotency_key' => $request->header('Idempotency-Key')]);

        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999.99'],
            'reason' => ['nullable', 'string', 'max:500'],
        ], [
            'idempotency_key.required' => 'This refund was sent without an Idempotency-Key header.',
        ]);

        $key = $validated['idempotency_key'];
        $seen = InvoicePayment::query()->where('idempotency_key', $key)->exists();

        try {
            $updated = $this->billing->refund(
                $invoice,
                $payment,
                (float) $validated['amount'],
                $validated['reason'] ?? null,
                $key,
            );
        } catch (BillingConflict $e) {
            return $this->fail($e->getMessage(), 422);
        }

        $resource = InvoiceResource::make($updated);

        return $seen
            ? $this->ok($resource, 'That refund was already recorded')
            : $this->created($resource, 'Refund recorded');
    }

    private function assertReachable(Invoice $invoice): void
    {
        if (! $this->branches->canUse(request()->user(), $invoice->location_id)) {
            abort(403, 'You do not have access to this invoice.');
        }
    }
}
