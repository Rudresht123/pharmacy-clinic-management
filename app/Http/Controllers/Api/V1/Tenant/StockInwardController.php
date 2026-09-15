<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Concerns\AuthorizesTenantUser;
use App\Http\Concerns\HandlesTableQueries;
use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\Api\V1\Tenant\StoreStockInwardRequest;
use App\Http\Resources\Tenant\StockInwardResource;
use App\Models\Tenant\PharmacyStore;
use App\Models\Tenant\StockInward;
use App\Services\Pharmacy\Inventory\StockInwardService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Goods received notes.
 *
 * Receiving is `pharmacy.inward`; cancelling one is `pharmacy.adjust`,
 * because it takes stock back off the books. Both are asked about the
 * store's branch through PharmacyStorePolicy.
 */
class StockInwardController extends BaseApiController
{
    use AuthorizesTenantUser, HandlesTableQueries;

    public function __construct(
        private readonly StockInwardService $inwards,
    ) {}

    public function index(Request $request, PharmacyStore $store): JsonResponse
    {
        $this->authorizeTenant('view', $store);

        $query = StockInward::query()
            ->where('pharmacy_store_id', $store->id)
            ->with(['supplier', 'store'])
            ->withCount('items')
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('inward_type'), fn (Builder $q) => $q->where('inward_type', $request->string('inward_type')->toString()));

        return $this->paginated(
            $this->tableQuery(
                $query,
                $request,
                searchable: ['inward_number', 'supplier_invoice_no'],
                sortable: ['received_date', 'inward_number', 'total_amount', 'created_at'],
                defaultSort: 'created_at',
            ),
            StockInwardResource::class,
        );
    }

    public function show(StockInward $inward): JsonResponse
    {
        $this->authorizeTenant('view', $inward->store);

        return $this->ok(StockInwardResource::make($inward->load(['items.medicine', 'supplier', 'store'])));
    }

    /** Post a note. A repeated Idempotency-Key answers 200 with the first one. */
    public function store(StoreStockInwardRequest $request, PharmacyStore $store): JsonResponse
    {
        $this->authorizeTenant('inward', $store);

        $data = $request->validated();

        [$inward, $created] = $this->inwards->receive($store, $data, $data['idempotency_key']);

        $resource = StockInwardResource::make($inward);

        return $created
            ? $this->created($resource, "{$inward->inward_number} received")
            : $this->ok($resource, "{$inward->inward_number} was already received");
    }

    public function cancel(Request $request, StockInward $inward): JsonResponse
    {
        $this->authorizeTenant('adjust', $inward->store);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $cancelled = $this->inwards->cancel($inward, $validated['reason']);

        return $this->ok(
            StockInwardResource::make($cancelled->load(['items.medicine', 'supplier', 'store'])),
            "{$cancelled->inward_number} cancelled",
        );
    }
}
