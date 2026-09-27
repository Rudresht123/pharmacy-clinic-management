<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Concerns\HandlesTableQueries;
use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\Api\V1\Tenant\StoreInvoiceRequest;
use App\Http\Resources\Tenant\InvoiceResource;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\BillingSetting;
use App\Models\Tenant\Invoice;
use App\Services\Billing\BillingConflict;
use App\Services\Billing\BillingService;
use App\Services\Billing\BillingTriggerResolver;
use App\Services\Tenancy\TenantBranchAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Clinic invoices.
 *
 * A separate register from the pharmacy's own bills — this holds the OPD's
 * consultations, procedures and named services. The two can coexist for the
 * same visit (a bill for the doctor's fee here, a bill for medicines at the
 * pharmacy counter), and both roll up under the visit's `awaiting_payment`
 * signal via VisitWorkflow.
 *
 * Every endpoint scopes to the branches this person may act at, via
 * TenantBranchAccess — owners see everywhere, staff see their memberships.
 */
class InvoiceController extends BaseApiController
{
    use HandlesTableQueries;

    public function __construct(
        private readonly BillingService $billing,
        private readonly BillingTriggerResolver $trigger,
        private readonly TenantBranchAccess $branches,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $allowed = $this->branches->allowed($request->user());

        $query = Invoice::query()
            ->with(['customer'])
            ->withCount('items')
            ->when($allowed !== null, fn (Builder $q) => $q->whereIn('location_id', $allowed))
            ->when(
                $request->filled('location_id'),
                fn (Builder $q) => $q->where('location_id', $request->integer('location_id')),
            )
            ->when(
                $request->filled('status'),
                fn (Builder $q) => $q->where('status', $request->string('status')->toString()),
            )
            ->when(
                $request->filled('payment_status'),
                fn (Builder $q) => $q->where('payment_status', $request->string('payment_status')->toString()),
            )
            ->when(
                $request->filled('customer_id'),
                fn (Builder $q) => $q->where('customer_id', $request->integer('customer_id')),
            )
            ->when(
                $request->filled('appointment_id'),
                fn (Builder $q) => $q->where('appointment_id', $request->integer('appointment_id')),
            )
            ->when(
                $request->filled('from'),
                fn (Builder $q) => $q->whereDate('invoice_date', '>=', $request->date('from')),
            )
            ->when(
                $request->filled('to'),
                fn (Builder $q) => $q->whereDate('invoice_date', '<=', $request->date('to')),
            )
            ->when(
                $request->filled('kind'),
                fn (Builder $q) => $q->where('kind', $request->string('kind')->toString()),
            )
            ->when(
                $request->boolean('outstanding_only'),
                fn (Builder $q) => $q->outstanding(),
            )
            /*
             * Drafts are a visit's running tab, not bills anybody can act on,
             * so the collection screen hides them unless asked. The one place
             * that wants them is the "still open" view a desk watches to see
             * which visits have not been closed off yet.
             */
            ->when(
                ! $request->boolean('include_drafts'),
                fn (Builder $q) => $q->whereNot('status', Invoice::STATUS_DRAFT),
            );

        return $this->paginated(
            $this->tableQuery(
                $query,
                $request,
                searchable: ['invoice_number', 'walk_in_name', 'walk_in_phone'],
                sortable: ['invoice_date', 'invoice_number', 'total_amount', 'created_at'],
                defaultSort: 'invoice_date',
            ),
            InvoiceResource::class,
        );
    }

    public function show(Invoice $invoice): JsonResponse
    {
        $this->assertReadable($invoice);

        return $this->ok(InvoiceResource::make(
            $invoice->load(['items', 'payments', 'customer']),
        ));
    }

    public function store(StoreInvoiceRequest $request): JsonResponse
    {
        $data = $request->validated();

        $invoice = $this->billing->draw([
            'location_id' => $data['location_id'],
            'customer_id' => $data['customer_id'] ?? null,
            'walk_in_name' => $data['walk_in_name'] ?? null,
            'walk_in_phone' => $data['walk_in_phone'] ?? null,
            'appointment_id' => $data['appointment_id'] ?? null,
            'consultation_id' => $data['consultation_id'] ?? null,
            'doctor_id' => $data['doctor_id'] ?? null,
            'trigger' => BillingSetting::TRIGGER_MANUAL,
            /*
             * THE EXCEPTION HATCH, and it is marked as one.
             *
             * A manual bill is never swept into a visit's consolidated
             * invoice — it is for the charges the workflow did not produce: a
             * certificate, a records fee, a dressing for somebody who is not
             * a patient today. Everything that belongs to a visit is drawn by
             * BillingTriggerResolver from the events themselves, and a
             * receptionist should never be typing a consultation line by hand.
             */
            'kind' => Invoice::KIND_MANUAL,
            'notes' => $data['notes'] ?? null,
        ], $data['items']);

        return $this->created(
            InvoiceResource::make($invoice),
            "{$invoice->invoice_number} raised",
        );
    }

    /**
     * Declare a visit's bill complete — it may now be paid.
     *
     * What the "Finalize" button on the billing screen calls, and what a
     * `manual`-trigger organisation uses for every visit. Collects anything
     * the visit has produced since the draft was last touched, then closes
     * it.
     */
    public function finalize(Request $request, Invoice $invoice): JsonResponse
    {
        $this->assertReadable($invoice);

        try {
            /*
             * Via the resolver rather than BillingService directly, so a
             * charge that landed after the last event — a dispensing while
             * the patient walked to the counter — is swept in before the
             * total is fixed.
             */
            if ($invoice->appointment_id) {
                $appointment = Appointment::query()->findOrFail($invoice->appointment_id);
                $finalized = $this->trigger->finalizeForVisit($appointment);
            } else {
                $finalized = $this->billing->finalize($invoice);
            }
        } catch (BillingConflict $e) {
            return $this->fail($e->getMessage(), 422);
        }

        if ($finalized === null) {
            return $this->fail('There is nothing to bill for this visit yet.', 422);
        }

        return $this->ok(
            InvoiceResource::make($finalized),
            "{$finalized->invoice_number} is ready for payment",
        );
    }

    /**
     * Rewrite an unpaid invoice's lines. Refused after any payment.
     */
    public function update(StoreInvoiceRequest $request, Invoice $invoice): JsonResponse
    {
        $this->assertReadable($invoice);

        $data = $request->validated();

        try {
            $updated = $this->billing->rewrite($invoice, $data['items'], $data['notes'] ?? null);
        } catch (BillingConflict $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->ok(
            InvoiceResource::make($updated),
            "{$updated->invoice_number} updated",
        );
    }

    /**
     * Draw and finalize a visit's bill from the billing screen.
     *
     * For the `manual`-trigger organisation, and for the visit whose
     * automatic trigger never fired — a doctor who charged nothing, a lab
     * order priced after the fact. Gathers everything the visit produced and
     * closes the bill in one step.
     */
    public function billVisit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'appointment_id' => ['required', 'integer', 'exists:appointments,id'],
        ]);

        $appointment = Appointment::query()->findOrFail($validated['appointment_id']);

        if (! $this->branches->canUse($request->user(), $appointment->location_id)) {
            return $this->fail('You are not permitted at this branch.', 403);
        }

        try {
            $invoice = $this->trigger->finalizeForVisit($appointment);
        } catch (BillingConflict $e) {
            return $this->fail($e->getMessage(), 422);
        }

        if ($invoice === null) {
            return $this->fail(
                'Nothing to bill for this visit yet — no consultation fee, lab charge or dispensing has been priced.',
                422,
            );
        }

        return $this->created(
            InvoiceResource::make($invoice),
            "{$invoice->invoice_number} is ready for payment",
        );
    }

    public function cancel(Request $request, Invoice $invoice): JsonResponse
    {
        $this->assertReadable($invoice);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        try {
            $cancelled = $this->billing->cancel($invoice, $validated['reason']);
        } catch (BillingConflict $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->ok(
            InvoiceResource::make($cancelled),
            "{$cancelled->invoice_number} cancelled",
        );
    }

    private function assertReadable(Invoice $invoice): void
    {
        if (! $this->branches->canUse(request()->user(), $invoice->location_id)) {
            abort(403, 'You do not have access to this invoice.');
        }
    }
}
