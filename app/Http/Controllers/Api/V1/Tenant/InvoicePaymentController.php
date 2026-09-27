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
 * the invoice's paid_amount and status columns from the sum of payments
 * (never from the request).
 */
class InvoicePaymentController extends BaseApiController
{
    public function __construct(
        private readonly BillingService $billing,
        private readonly TenantBranchAccess $branches,
    ) {}

    /** Record a payment against an invoice. */
    public function store(Request $request, Invoice $invoice): JsonResponse
    {
        $this->assertReachable($invoice);

        $validated = $request->validate([
            'method' => ['required', 'string', Rule::in(InvoicePayment::METHODS)],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999.99'],
            'reference' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $updated = $this->billing->recordPayment($invoice, $validated);
        } catch (BillingConflict $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->created(
            InvoiceResource::make($updated),
            'Payment recorded',
        );
    }

    /** Refund an already-recorded payment. */
    public function refund(Request $request, Invoice $invoice, InvoicePayment $payment): JsonResponse
    {
        $this->assertReachable($invoice);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999.99'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $updated = $this->billing->refund(
                $invoice,
                $payment,
                (float) $validated['amount'],
                $validated['reason'] ?? null,
            );
        } catch (BillingConflict $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->created(
            InvoiceResource::make($updated),
            'Refund recorded',
        );
    }

    private function assertReachable(Invoice $invoice): void
    {
        if (! $this->branches->canUse(request()->user(), $invoice->location_id)) {
            abort(403, 'You do not have access to this invoice.');
        }
    }
}
