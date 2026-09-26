<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Concerns\AuthorizesTenantUser;
use App\Http\Concerns\HandlesTableQueries;
use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\Api\V1\Tenant\StorePharmacySaleRequest;
use App\Http\Resources\Tenant\PharmacySaleResource;
use App\Models\Tenant\PharmacySale;
use App\Models\Tenant\PharmacyStore;
use App\Services\Pharmacy\Sales\SalesService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            $sale->load(['items.medicine', 'payments', 'customer', 'store']),
        ));
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
