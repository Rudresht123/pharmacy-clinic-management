<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Concerns\HandlesTableQueries;
use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\Api\V1\Tenant\StoreInvoiceRequest;
use App\Http\Resources\Tenant\InvoiceResource;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\BillingSetting;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\InvoiceItem;
use App\Models\Tenant\InvoicePayment;
use App\Services\Billing\BillingConflict;
use App\Services\Billing\BillingService;
use App\Services\Billing\BillingTriggerResolver;
use App\Services\Tenancy\TenantBranchAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        $query = $this->narrowed($this->scoped($request), $request)
            // The list's Visit Type column reads the lines; its Patient column the phone.
            ->with(['customer', 'items', 'doctor'])
            ->withCount('items');

        /*
         * Two columns the screen sorts by are not columns of this table: the
         * patient's name lives on customers, and what is owed is a
         * difference. Both are handled here; everything else goes through
         * the whitelisted sort.
         */
        $sort = (string) $request->query('sort', '');

        if (in_array($sort, ['patient', 'outstanding'], true)) {
            $direction = strtolower((string) $request->query('direction')) === 'asc' ? 'asc' : 'desc';

            if ($sort === 'patient') {
                $query->orderBy(
                    Customer::query()->select('name')->whereColumn('customers.id', 'invoices.customer_id'),
                    $direction,
                )->orderBy('walk_in_name', $direction);
            } else {
                $query->orderByRaw('(total_amount - paid_amount) '.$direction);
            }

            $page = $query->orderByDesc('id')->paginate($this->resolvePerPage($request))->withQueryString();
        } else {
            $page = $this->tableQuery(
                $query,
                $request,
                sortable: ['invoice_date', 'invoice_number', 'total_amount', 'paid_amount', 'status', 'created_at'],
                defaultSort: 'invoice_date',
            );
        }

        return $this->paginated($page, InvoiceResource::class);
    }

    /**
     * The figures above the list: the four cards, and a count on every tab.
     *
     * The COUNTS follow the filters on screen — search, dates, type — so a
     * tab never promises twelve bills and opens on three. The CARDS do not:
     * they are the register as a whole, this month against last, which is
     * what somebody glancing at the top of the page is asking.
     */
    public function summary(Request $request): JsonResponse
    {
        $filtered = $this->scoped($request);

        $counts = [
            'outstanding' => (clone $filtered)->outstanding()->count(),
            'open' => (clone $filtered)->where('status', Invoice::STATUS_DRAFT)->count(),
            'all' => (clone $filtered)->count(),
            'paid' => (clone $filtered)->where('status', Invoice::STATUS_PAID)->count(),
            'cancelled' => (clone $filtered)->where('status', Invoice::STATUS_CANCELLED)->count(),
        ];

        $allowed = $this->branches->allowed($request->user());
        $branch = $request->filled('location_id') ? $request->integer('location_id') : null;

        $register = Invoice::query()
            ->when($allowed !== null, fn (Builder $q) => $q->whereIn('location_id', $allowed))
            ->when($branch !== null, fn (Builder $q) => $q->where('location_id', $branch))
            ->whereNotIn('status', [Invoice::STATUS_DRAFT, Invoice::STATUS_CANCELLED]);

        $thisMonth = [Carbon::today()->startOfMonth(), Carbon::today()->endOfMonth()];
        $lastMonth = [Carbon::today()->subMonthNoOverflow()->startOfMonth(), Carbon::today()->subMonthNoOverflow()->endOfMonth()];

        $in = fn (array $month) => (clone $register)->whereBetween('invoice_date', [
            $month[0]->toDateString(),
            $month[1]->toDateString(),
        ]);

        $owing = fn (Builder $q) => round((float) (clone $q)->outstanding()->sum(DB::raw('total_amount - paid_amount')), 2);

        // Money actually taken in a month — by when it was paid, refunds netted.
        $collectedIn = fn (array $month) => round((float) InvoicePayment::query()
            ->whereHas('invoice', function (Builder $q) use ($allowed, $branch) {
                $q->when($allowed !== null, fn (Builder $inner) => $inner->whereIn('location_id', $allowed))
                    ->when($branch !== null, fn (Builder $inner) => $inner->where('location_id', $branch))
                    ->where('status', '!=', Invoice::STATUS_CANCELLED);
            })
            ->whereBetween('paid_at', $month)
            ->sum(DB::raw('CASE WHEN is_refund THEN -amount ELSE amount END')), 2);

        $change = fn (float $now, float $before) => $before > 0 ? round((($now - $before) / $before) * 100) : null;

        $billedNow = round((float) $in($thisMonth)->sum('total_amount'), 2);
        $countNow = $in($thisMonth)->count();
        $collectedNow = $collectedIn($thisMonth);

        return $this->ok([
            'counts' => $counts,
            'cards' => [
                'invoices' => [
                    'value' => (clone $register)->count(),
                    'this_month' => $countNow,
                    'change' => $change($countNow, $in($lastMonth)->count()),
                ],
                'billed' => [
                    'value' => round((float) (clone $register)->sum('total_amount'), 2),
                    'this_month' => $billedNow,
                    'change' => $change($billedNow, round((float) $in($lastMonth)->sum('total_amount'), 2)),
                ],
                'outstanding' => [
                    'value' => $owing($register),
                    'invoices' => (clone $register)->outstanding()->count(),
                    // Of the bills raised this month, how much is still owed — against last month's.
                    'change' => $change($owing($in($thisMonth)), $owing($in($lastMonth))),
                ],
                'collected' => [
                    'value' => round((float) (clone $register)->sum('paid_amount'), 2),
                    'invoices' => (clone $register)->where('status', Invoice::STATUS_PAID)->count(),
                    'change' => $change($collectedNow, $collectedIn($lastMonth)),
                ],
            ],
        ]);
    }

    /**
     * The list as it stands on screen, as a spreadsheet.
     *
     * The same filters as index(), every page of them — or only the rows
     * somebody ticked, when `ids` is given. CSV rather than .xlsx: it opens in
     * Excel and anything else, and needs no library to write.
     */
    public function export(Request $request): StreamedResponse
    {
        $request->validate([
            'ids' => ['sometimes', 'array', 'max:1000'],
            'ids.*' => ['integer'],
        ]);

        $query = $this->narrowed($this->scoped($request), $request)
            ->with(['customer'])
            ->withCount('items')
            ->when($request->filled('ids'), fn (Builder $q) => $q->whereIn('id', $request->input('ids')))
            ->orderByDesc('invoice_date')
            ->orderByDesc('id');

        $name = 'invoices-'.Carbon::today()->toDateString().'.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');

            // So Excel reads ₹ and names in any script as UTF-8.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, ['Invoice No', 'Date', 'Patient', 'Phone', 'Kind', 'Items', 'Total', 'Paid', 'Outstanding', 'Status']);

            $query->chunk(500, function ($invoices) use ($out) {
                foreach ($invoices as $invoice) {
                    fputcsv($out, [
                        $invoice->invoice_number,
                        $invoice->invoice_date?->toDateString(),
                        $invoice->customer?->name ?? $invoice->walk_in_name ?? 'Walk-in',
                        $invoice->customer?->phone ?? $invoice->walk_in_phone,
                        ucfirst((string) $invoice->kind),
                        $invoice->items_count,
                        number_format((float) $invoice->total_amount, 2, '.', ''),
                        number_format((float) $invoice->paid_amount, 2, '.', ''),
                        number_format($invoice->outstanding(), 2, '.', ''),
                        str_replace('_', ' ', (string) $invoice->status),
                    ]);
                }
            });

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Which bills: the branches this person may see, and whatever the
     * screen's search, dates, type, patient or visit narrow them to. Not
     * yet which TAB — see narrowed() — so the tab counts can share it.
     */
    private function scoped(Request $request): Builder
    {
        $allowed = $this->branches->allowed($request->user());

        return Invoice::query()
            ->when($allowed !== null, fn (Builder $q) => $q->whereIn('location_id', $allowed))
            ->when(
                $request->filled('location_id'),
                fn (Builder $q) => $q->where('location_id', $request->integer('location_id')),
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
            /*
             * A bill's TYPE is what is on it: a bill with a lab line is found
             * under Lab Test whatever else it carries. `other` is the named
             * services and hand-typed lines.
             */
            ->when($request->filled('type'), function (Builder $q) use ($request) {
                $sources = match ($request->string('type')->toString()) {
                    'consultation' => [InvoiceItem::SOURCE_CONSULTATION],
                    'lab_test' => [InvoiceItem::SOURCE_LAB_TEST],
                    'pharmacy' => [InvoiceItem::SOURCE_PHARMACY_SALE_ITEM],
                    'procedure' => [InvoiceItem::SOURCE_PROCEDURE],
                    'other' => [InvoiceItem::SOURCE_SERVICE, InvoiceItem::SOURCE_CUSTOM],
                    default => null,
                };

                if ($sources !== null) {
                    $q->whereHas('items', fn (Builder $items) => $items->whereIn('source_type', $sources));
                }
            })
            /*
             * Search reaches the patient's own name and phone, not only a
             * walk-in's — the desk searches for "Sneha", not for whether she
             * was registered.
             */
            ->when(trim((string) $request->query('search', '')) !== '', function (Builder $q) use ($request) {
                $pattern = '%'.trim((string) $request->query('search')).'%';

                $q->where(function (Builder $any) use ($pattern) {
                    $any->where('invoice_number', 'ILIKE', $pattern)
                        ->orWhere('walk_in_name', 'ILIKE', $pattern)
                        ->orWhere('walk_in_phone', 'ILIKE', $pattern)
                        ->orWhereHas('customer', fn (Builder $customer) => $customer
                            ->where('name', 'ILIKE', $pattern)
                            ->orWhere('phone', 'ILIKE', $pattern));
                });
            });
    }

    /** The tab and status on top of scoped(): which of those bills this list shows. */
    private function narrowed(Builder $query, Request $request): Builder
    {
        return $query
            ->when(
                $request->filled('status'),
                fn (Builder $q) => $q->where('status', $request->string('status')->toString()),
            )
            ->when(
                $request->filled('payment_status'),
                fn (Builder $q) => $q->where('payment_status', $request->string('payment_status')->toString()),
            )
            ->when(
                $request->boolean('outstanding_only'),
                fn (Builder $q) => $q->outstanding(),
            )
            /*
             * How long the bill has been sitting — the Outstanding screen's
             * own filter, same four buckets as BillingReports::ageing(). A
             * separate control from the date range above: that one asks when
             * the bill was RAISED, this one asks how OLD the debt is today.
             */
            ->when($request->filled('aging'), function (Builder $q) use ($request) {
                $today = Carbon::today();

                match ($request->string('aging')->toString()) {
                    'fresh' => $q->whereDate('invoice_date', '>=', $today->copy()->subDays(7)),
                    'recent' => $q
                        ->whereDate('invoice_date', '<', $today->copy()->subDays(7))
                        ->whereDate('invoice_date', '>=', $today->copy()->subDays(30)),
                    'stale' => $q
                        ->whereDate('invoice_date', '<', $today->copy()->subDays(30))
                        ->whereDate('invoice_date', '>=', $today->copy()->subDays(60)),
                    'old' => $q->whereDate('invoice_date', '<', $today->copy()->subDays(60)),
                    default => null,
                };
            })
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
