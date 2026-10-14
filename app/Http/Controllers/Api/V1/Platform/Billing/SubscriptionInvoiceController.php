<?php

namespace App\Http\Controllers\Api\V1\Platform\Billing;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Platform\Organization;
use App\Models\Platform\Plan;
use App\Models\Platform\Subscription;
use App\Models\Platform\SubscriptionInvoice;
use App\Models\Platform\SubscriptionPayment;
use App\Services\Subscriptions\Actor;
use App\Services\Subscriptions\InvoiceService;
use App\Services\Subscriptions\Payments\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Invoices and payments across the platform — listing them, issuing a
 * renewal invoice by hand, voiding one, and recording money received outside
 * Razorpay (bank transfer, UPI, cheque).
 *
 * Recording a manual payment runs the same PaymentConfirmation a verified
 * Razorpay payment does: the invoice is paid and what it was for — usually a
 * renewal — happens, unlocking the organization if the lapse was its reason
 * for being locked.
 *
 * @group Platform > Billing
 */
class SubscriptionInvoiceController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::in(['draft', 'issued', 'paid', 'void'])],
            'organization' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = SubscriptionInvoice::query()
            ->with(['organization:id,uuid,organization_name', 'payments'])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('organization'), fn ($q, $uuid) => $q->whereHas('organization', fn ($o) => $o->where('uuid', $uuid)))
            ->latest('id')
            ->paginate((int) $request->query('per_page', 25));

        return $this->ok(
            collect($page->items())->map(fn (SubscriptionInvoice $i) => $this->invoice($i)),
            null,
            ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        );
    }

    public function show(SubscriptionInvoice $invoice): JsonResponse
    {
        return $this->ok($this->invoice($invoice->load(['organization', 'payments'])));
    }

    /** Issue (or return the open) renewal invoice for an organization. */
    public function issueRenewal(Request $request, Organization $organization, InvoiceService $invoices): JsonResponse
    {
        $data = $request->validate([
            'plan_id' => ['nullable', 'integer', Rule::exists(Plan::class, 'id')],
            'billing_cycle' => ['nullable', Rule::in([Subscription::MONTHLY, Subscription::ANNUAL])],
        ]);

        $subscription = Subscription::query()->where('organization_id', $organization->id)->where('is_current', true)->firstOrFail();

        $invoice = $invoices->renewalInvoice(
            $subscription,
            isset($data['plan_id']) ? Plan::query()->findOrFail($data['plan_id']) : null,
            $data['billing_cycle'] ?? null,
        );

        return $this->created($this->invoice($invoice->load(['organization', 'payments'])), 'Renewal invoice issued.');
    }

    public function void(Request $request, SubscriptionInvoice $invoice, InvoiceService $invoices): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']]);

        return $this->ok($this->invoice($invoices->void($invoice, $data['reason'])->load(['organization', 'payments'])), 'Invoice voided.');
    }

    /** Money received another way, recorded — the invoice is paid and fulfilled. */
    public function recordPayment(Request $request, SubscriptionInvoice $invoice, PaymentService $payments): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'reference' => ['required', 'string', 'min:3', 'max:120'],
            'received_at' => ['nullable', 'date', 'before_or_equal:now'],
        ]);

        $paid = $payments->recordManual(
            $invoice,
            (int) $data['amount'],
            $data['reference'],
            Actor::platform($request->user('platform')),
            isset($data['received_at']) ? Carbon::parse($data['received_at']) : null,
        );

        return $this->ok($this->invoice($paid->load(['organization', 'payments'])), 'Payment recorded.');
    }

    /** @return array<string, mixed> */
    private function invoice(SubscriptionInvoice $invoice): array
    {
        return [
            'uuid' => $invoice->uuid,
            'number' => $invoice->number,
            'organization' => $invoice->organization ? [
                'uuid' => $invoice->organization->uuid,
                'name' => $invoice->organization->organization_name,
            ] : null,
            'kind' => $invoice->kind,
            'status' => $invoice->status,
            'currency' => $invoice->currency,
            'lines' => $invoice->lines,
            'subtotal' => $invoice->subtotal,
            'tax_mode' => $invoice->tax_mode,
            'tax_rate_bp' => $invoice->tax_rate_bp,
            'tax' => $invoice->tax,
            'total' => $invoice->total,
            'period_start' => $invoice->period_start?->toIso8601String(),
            'period_end' => $invoice->period_end?->toIso8601String(),
            'bill_to' => $invoice->bill_to,
            'issued_at' => $invoice->issued_at?->toIso8601String(),
            'due_at' => $invoice->due_at?->toIso8601String(),
            'paid_at' => $invoice->paid_at?->toIso8601String(),
            'voided_at' => $invoice->voided_at?->toIso8601String(),
            'void_reason' => $invoice->void_reason,
            'fulfilled_at' => $invoice->fulfilled_at?->toIso8601String(),
            'payments' => $invoice->payments->map(fn (SubscriptionPayment $p) => [
                'uuid' => $p->uuid,
                'method' => $p->method,
                'status' => $p->status,
                'amount' => $p->amount,
                'gateway_order_id' => $p->gateway_order_id,
                'gateway_payment_id' => $p->gateway_payment_id,
                'reference' => $p->reference,
                'verified_at' => $p->verified_at?->toIso8601String(),
                'verified_via' => $p->verified_via,
                'failure_reason' => $p->failure_reason,
            ]),
        ];
    }
}
