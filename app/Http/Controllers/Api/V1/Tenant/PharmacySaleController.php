<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Concerns\AuthorizesTenantUser;
use App\Http\Concerns\HandlesTableQueries;
use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\Api\V1\Tenant\StorePharmacySaleRequest;
use App\Http\Resources\Tenant\PharmacySaleResource;
use App\Models\Tenant\PharmacySale;
use App\Models\Tenant\PharmacySalePayment;
use App\Models\Tenant\PharmacyStore;
use App\Services\Pharmacy\Sales\SalesService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Bills.
 *
 * A counter sale is `pharmacy.sell`; a dispensing — the same bill carrying a
 * prescription — is `pharmacy.dispense`, because it credits prescribed lines
 * and can be the thing that closes a visit. Cancelling is
 * `pharmacy.sale_cancel`, because it puts stock back and takes money off the
 * day's takings.
 *
 * All three are asked about the store's own branch through
 * PharmacyStorePolicy, not the branch the request happens to be acting at.
 *
 * @group Tenant > Pharmacy > Stores & Sales
 */
class PharmacySaleController extends BaseApiController
{
    use AuthorizesTenantUser, HandlesTableQueries;

    public function __construct(
        private readonly SalesService $sales,
    ) {}

    /** The day's bills at one store, newest first. */
    public function index(Request $request, PharmacyStore $store): JsonResponse
    {
        $this->authorizeTenant('view', $store);

        $query = PharmacySale::query()
            ->where('pharmacy_store_id', $store->id)
            ->with(['customer', 'store'])
            ->withCount('items')
            ->when(
                $request->filled('status'),
                fn (Builder $q) => $q->where('status', $request->string('status')->toString()),
            )
            ->when(
                $request->filled('payment_status'),
                fn (Builder $q) => $q->where('payment_status', $request->string('payment_status')->toString()),
            )
            ->when(
                $request->filled('from'),
                fn (Builder $q) => $q->whereDate('sale_date', '>=', $request->date('from')),
            )
            ->when(
                $request->filled('to'),
                fn (Builder $q) => $q->whereDate('sale_date', '<=', $request->date('to')),
            )
            ->when(
                $request->boolean('unpaid_only'),
                fn (Builder $q) => $q->whereIn('payment_status', [PharmacySale::PARTIAL, PharmacySale::UNPAID])
                    ->where('status', PharmacySale::COMPLETED),
            );

        return $this->paginated(
            $this->tableQuery(
                $query,
                $request,
                searchable: ['sale_number', 'walk_in_name', 'walk_in_phone'],
                sortable: ['sale_date', 'sale_number', 'total_amount', 'created_at'],
                defaultSort: 'sale_date',
            ),
            PharmacySaleResource::class,
        );
    }

    public function show(PharmacySale $sale): JsonResponse
    {
        $this->authorizeTenant('view', $sale->store);

        return $this->ok(PharmacySaleResource::make(
            $sale->load(['items.medicine', 'payments', 'customer', 'doctor', 'store', 'returns.items']),
        ));
    }

    /**
     * Take money against a bill left owing — at this counter, not at Billing.
     *
     * Whoever may sell or dispense here may collect: the person at the till.
     * A repeated Idempotency-Key answers 200 with the bill as it stands.
     */
    public function collect(Request $request, PharmacySale $sale): JsonResponse
    {
        abort_unless(
            $this->tenantMay('sell', $sale->store) || $this->tenantMay('dispense', $sale->store),
            403,
            'You cannot take payment at this store.',
        );

        $request->merge(['idempotency_key' => $request->header('Idempotency-Key')]);

        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:100'],
            'tenders' => ['required', 'array', 'min:1', 'max:5'],
            'tenders.*.method' => ['required', 'string', Rule::in(PharmacySalePayment::METHODS)],
            'tenders.*.amount' => ['required', 'numeric', 'min:0.01', 'max:9999999.99'],
            'tenders.*.reference' => ['nullable', 'string', 'max:60'],
        ], [
            'idempotency_key.required' => 'This payment was sent without an Idempotency-Key header.',
        ]);

        [$updated, $recorded] = $this->sales->collect($sale, $validated['tenders'], $validated['idempotency_key']);

        $resource = PharmacySaleResource::make($updated);

        return $recorded
            ? $this->created($resource, "Payment taken on {$updated->sale_number}")
            : $this->ok($resource, 'That payment was already taken');
    }

    /**
     * Take goods back against a bill — some of it, or all of it.
     *
     * The same authority as cancelling a bill (`pharmacy.sale_cancel`): both
     * put stock back and take money off the day's takings.
     */
    public function returnItems(Request $request, PharmacySale $sale): JsonResponse
    {
        $this->authorizeTenant('cancelSale', $sale->store);

        $request->merge(['idempotency_key' => $request->header('Idempotency-Key')]);

        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:100'],
            'reason' => ['required', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.pharmacy_sale_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'refund_method' => ['nullable', 'string', Rule::in(PharmacySalePayment::METHODS)],
            'refund_reference' => ['nullable', 'string', 'max:60'],
        ], [
            'idempotency_key.required' => 'This return was sent without an Idempotency-Key header.',
        ]);

        [$updated, $recorded] = $this->sales->returnItems(
            $sale,
            $validated['items'],
            ['method' => $validated['refund_method'] ?? null, 'reference' => $validated['refund_reference'] ?? null],
            $validated['reason'],
            $validated['idempotency_key'],
        );

        $resource = PharmacySaleResource::make($updated);

        return $recorded
            ? $this->created($resource, "Return recorded on {$updated->sale_number}")
            : $this->ok($resource, 'That return was already recorded');
    }

    /** Ring up a sale. A repeated Idempotency-Key answers 200 with the first bill. */
    public function store(StorePharmacySaleRequest $request, PharmacyStore $store): JsonResponse
    {
        $data = $request->validated();

        /*
         * Two acts, one endpoint, two questions.
         *
         * A bill carrying a prescription credits prescribed lines and can
         * close a visit, which is not the same thing as ringing up a tube of
         * cream — so it asks `dispense` rather than `sell`. The route admits
         * either capability; this is where they are told apart.
         */
        $this->authorizeTenant(
            empty($data['prescription_id']) ? 'sell' : 'dispense',
            $store,
        );

        [$sale, $created] = $this->sales->sell($store, $data, $data['idempotency_key']);

        $resource = PharmacySaleResource::make($sale);

        return $created
            ? $this->created($resource, "{$sale->sale_number} billed")
            : $this->ok($resource, "{$sale->sale_number} was already billed");
    }

    /** Cancel a bill: the stock goes back, and the bill stays on the record. */
    public function cancel(Request $request, PharmacySale $sale): JsonResponse
    {
        $this->authorizeTenant('cancelSale', $sale->store);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $cancelled = $this->sales->cancel($sale, $validated['reason']);

        return $this->ok(
            PharmacySaleResource::make($cancelled),
            "{$cancelled->sale_number} cancelled",
        );
    }
}
