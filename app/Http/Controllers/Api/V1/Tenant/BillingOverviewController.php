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
            ->with('customer')
            ->withCount('items')
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
     * Every payment taken, newest first — the register a till reconciles from.
     *
     * Refunds are rows here too, marked as such rather than filtered out: a
     * register that hides them cannot be reconciled against a drawer.
     */
    public function payments(Request $request): JsonResponse
    {
        $allowed = $this->branches->allowed($request->user());

        $query = InvoicePayment::query()
            ->with(['invoice.customer'])
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
            );

        $page = $this->tableQuery(
            $query,
            $request,
            searchable: [],
            sortable: ['paid_at', 'amount'],
            defaultSort: 'paid_at',
        );

        return response()->json([
            'data' => collect($page->items())->map(fn (InvoicePayment $payment) => [
                'id' => $payment->id,
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
     * The window being reported on. Defaults to the last seven days, which is
     * what the trend chart shows and what a counter is usually asking about.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function window(Request $request): array
    {
        $to = $request->filled('to')
            ? Carbon::parse($request->date('to'))->endOfDay()
            : Carbon::today()->endOfDay();

        $from = $request->filled('from')
            ? Carbon::parse($request->date('from'))->startOfDay()
            : (clone $to)->subDays(6)->startOfDay();

        return [$from, $to];
    }
}
