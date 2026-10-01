<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Concerns\HandlesTableQueries;
use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Resources\Tenant\InvoiceResource;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\InvoicePayment;
use App\Services\Billing\BillingReports;
use App\Services\Tenancy\TenantBranchAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The billing counter's own screens: the overview, and the payments register.
 *
 * Everything here is scoped to the branches this person may act at, the same
 * way the invoice list is — an overview that summed branches somebody cannot
 * open would report money they are not allowed to see.
 */
class BillingOverviewController extends BaseApiController
{
    use HandlesTableQueries;

    public function __construct(
        private readonly BillingReports $reports,
        private readonly TenantBranchAccess $branches,
    ) {}

    /**
     * Figures for the overview: cards, trend, status breakdown, recent bills.
     *
     * One request rather than five, because they are read together and a
     * dashboard that paints in five stages reads as a slow one.
     */
    public function show(Request $request): JsonResponse
    {
        [$from, $to] = $this->window($request);

        $allowed = $this->branches->allowed($request->user());
        $asked = $request->filled('location_id') ? $request->integer('location_id') : null;

        if ($asked !== null && ! $this->branches->canUse($request->user(), $asked)) {
            return $this->fail('You are not permitted at that branch.', 403);
        }

        $recent = Invoice::query()
            ->with(['customer', 'doctor', 'items'])
            ->when($allowed !== null, fn (Builder $q) => $q->whereIn('location_id', $allowed))
            ->when($asked !== null, fn (Builder $q) => $q->where('location_id', $asked))
            ->whereNot('status', Invoice::STATUS_DRAFT)
            ->orderByDesc('invoice_date')
            ->limit(5)
            ->get();

        return $this->ok([
            ...$this->reports->overview($from, $to, $allowed, $asked),
            'recent' => InvoiceResource::collection($recent),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ]);
    }

    /**
     * The dashboard's invoice table: the window's bills, a page at a time.
     *
     * Its own endpoint rather than a page parameter on show(): turning a page
     * of this table must not recompute every card and chart above it. Loaded
     * with items and doctor, which the table's Type and Doctor columns read
     * and the plain invoice list does not carry.
     *
     * Newest first, and by id within a day, so a page boundary never falls
     * between two bills of the same date in a different order each time.
     */
    public function invoices(Request $request): JsonResponse
    {
        [$from, $to] = $this->window($request);
        [$allowed, $asked] = $this->scope($request);

        $page = Invoice::query()
            ->with(['customer', 'doctor', 'items'])
            ->when($allowed !== null, fn (Builder $q) => $q->whereIn('location_id', $allowed))
            ->when($asked !== null, fn (Builder $q) => $q->where('location_id', $asked))
            ->whereNot('status', Invoice::STATUS_DRAFT)
            ->whereDate('invoice_date', '>=', $from->toDateString())
            ->whereDate('invoice_date', '<=', $to->toDateString())
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->paginate($this->resolvePerPage($request));

        return $this->paginated($page, InvoiceResource::class);
    }

    /**
     * Who owes money, grouped by patient, a page at a time.
     *
     * Not windowed: money owed from last month is still owed today, and the
     * table would otherwise lose a debt the moment its bill aged out of the
     * range at the top of the page.
     */
    public function outstanding(Request $request): JsonResponse
    {
        [$allowed, $asked] = $this->scope($request);

        $page = $this->reports->outstandingPatientsPage($allowed, $asked, $this->resolvePerPage($request));

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
            ],
        ]);
    }

    /**
     * The payments register's own cards and tender tabs.
     */
    public function paymentsSummary(Request $request): JsonResponse
    {
        [$from, $to] = $this->window($request);
        [$allowed, $asked] = $this->scope($request);

        return $this->ok($this->reports->paymentsSummary($from, $to, $allowed, $asked));
    }

    /**
     * The outstanding register's own cards — unpaid against partially paid.
     *
     * The date range is optional here, unlike every other window on this
     * controller: outstanding money does not age out just because it falls
     * off the front of a range, so the figures are all-time unless the
     * screen's own filter actually narrows them.
     */
    public function outstandingSummary(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        [$allowed, $asked] = $this->scope($request);

        $from = $request->filled('from') ? Carbon::parse($request->date('from'))->startOfDay() : null;
        $to = $request->filled('to') ? Carbon::parse($request->date('to'))->endOfDay() : null;

        return $this->ok($this->reports->outstandingSummary($allowed, $asked, $from, $to));
    }

    /**
     * The branches this person may see, and the one they asked for.
     *
     * @return array{0: list<int>|null, 1: int|null}
     */
    private function scope(Request $request): array
    {
        $allowed = $this->branches->allowed($request->user());
        $asked = $request->filled('location_id') ? $request->integer('location_id') : null;

        if ($asked !== null && ! $this->branches->canUse($request->user(), $asked)) {
            abort(403, 'You are not permitted at that branch.');
        }

        return [$allowed, $asked];
    }

    /**
     * Every payment taken, newest first — the register a till reconciles from.
     *
     * Refunds are rows here too, marked as such rather than filtered out: a
     * register that hides them cannot be reconciled against a drawer.
     */
    public function payments(Request $request): JsonResponse
    {
        $page = $this->tableQuery(
            $this->paymentsQuery($request),
            $request,
            searchable: [],
            sortable: ['paid_at', 'amount'],
            defaultSort: 'paid_at',
        );

        return response()->json([
            'data' => collect($page->items())->map(fn (InvoicePayment $payment) => [
                'id' => $payment->id,
                'receipt_number' => $payment->receipt_number,
                'invoice_id' => $payment->invoice_id,
                'invoice_number' => $payment->invoice?->invoice_number,
                'patient_name' => $payment->invoice?->customer?->name
                    ?? $payment->invoice?->walk_in_name,
                'amount' => (float) $payment->amount,
                'method' => $payment->method,
                'reference' => $payment->reference,
                'notes' => $payment->notes,
                'paid_at' => $payment->paid_at?->toIso8601String(),
                'is_refund' => (bool) $payment->is_refund,
            ]),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
            ],
        ]);
    }

    /**
     * The payments register as a spreadsheet — every row the filters match,
     * not just the page on screen.
     */
    public function exportPayments(Request $request): StreamedResponse
    {
        $query = $this->paymentsQuery($request)->orderByDesc('paid_at')->orderByDesc('id');

        $name = 'payments-'.Carbon::today()->toDateString().'.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');

            // So Excel reads ₹ and names in any script as UTF-8.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, ['Date', 'Receipt #', 'Invoice #', 'Patient', 'Amount', 'Method', 'Reference', 'Type']);

            $query->with(['invoice.customer'])->chunk(500, function ($payments) use ($out) {
                foreach ($payments as $payment) {
                    fputcsv($out, [
                        $payment->paid_at?->toDateTimeString(),
                        $payment->receipt_number,
                        $payment->invoice?->invoice_number,
                        $payment->invoice?->customer?->name ?? $payment->invoice?->walk_in_name ?? 'Walk-in',
                        number_format((float) $payment->amount, 2, '.', ''),
                        str_replace('_', ' ', (string) $payment->method),
                        $payment->reference,
                        $payment->is_refund ? 'Refund' : 'Payment',
                    ]);
                }
            });

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Which payments: the branches this person may see, and whatever the
     * payments screen's own filters narrow them to. Shared by the paged list
     * and the CSV export, so the two never quietly disagree about scope.
     */
    private function paymentsQuery(Request $request): Builder
    {
        $allowed = $this->branches->allowed($request->user());

        return InvoicePayment::query()
            ->whereHas('invoice', function (Builder $q) use ($allowed, $request) {
                $q->when($allowed !== null, fn (Builder $inner) => $inner->whereIn('location_id', $allowed))
                    ->when(
                        $request->filled('location_id'),
                        fn (Builder $inner) => $inner->where('location_id', $request->integer('location_id')),
                    );
            })
            ->when(
                $request->filled('method'),
                fn (Builder $q) => $q->where('method', $request->string('method')->toString()),
            )
            ->when(
                $request->filled('from'),
                fn (Builder $q) => $q->whereDate('paid_at', '>=', $request->date('from')),
            )
            ->when(
                $request->filled('to'),
                fn (Builder $q) => $q->whereDate('paid_at', '<=', $request->date('to')),
            )
            ->when($request->filled('search'), function (Builder $q) use ($request) {
                $term = '%'.$request->string('search')->toString().'%';

                // receipt_number lives on this table; invoice number and the
                // patient's name or phone live across the relation — so the
                // generic `searchable` columns HandlesTableQueries matches
                // against cannot reach them, and this is spelled out by hand.
                $q->where(function (Builder $outer) use ($term) {
                    $outer->where('receipt_number', 'ILIKE', $term)
                        ->orWhereHas('invoice', function (Builder $invoice) use ($term) {
                            $invoice->where('invoice_number', 'ILIKE', $term)
                                ->orWhere('walk_in_name', 'ILIKE', $term)
                                ->orWhere('walk_in_phone', 'ILIKE', $term)
                                ->orWhereHas('customer', function (Builder $customer) use ($term) {
                                    $customer->where('name', 'ILIKE', $term)
                                        ->orWhere('phone', 'ILIKE', $term);
                                });
                        });
                });
            });
    }

    /**
     * The window being reported on. Defaults to the last seven days, which is
     * what the trend chart shows and what a counter is usually asking about.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function window(Request $request): array
    {
        // A custom range is typed by a person, so it is checked, not parsed blind.
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $to = $request->filled('to')
            ? Carbon::parse($request->date('to'))->endOfDay()
            : Carbon::today()->endOfDay();

        $from = $request->filled('from')
            ? Carbon::parse($request->date('from'))->startOfDay()
            : (clone $to)->subDays(6)->startOfDay();

        return [$from, $to];
    }
}
