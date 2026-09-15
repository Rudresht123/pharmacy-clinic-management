<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Concerns\AuthorizesTenantUser;
use App\Http\Concerns\HandlesTableQueries;
use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\Api\V1\Tenant\StoreStockTransferRequest;
use App\Http\Resources\Tenant\StockTransferResource;
use App\Models\Tenant\PharmacyStore;
use App\Models\Tenant\StockTransfer;
use App\Services\Pharmacy\Inventory\StockTransferService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Transfers between stores. Asking `transfer` of both stores is what makes
 * a transfer need access to both branches.
 */
class StockTransferController extends BaseApiController
{
    use AuthorizesTenantUser, HandlesTableQueries;

    public function __construct(
        private readonly StockTransferService $transfers,
    ) {}

    /** Transfers out of or into this store. */
    public function index(Request $request, PharmacyStore $store): JsonResponse
    {
        $this->authorizeTenant('view', $store);

        $query = StockTransfer::query()
            ->where(fn (Builder $q) => $q->where('from_store_id', $store->id)->orWhere('to_store_id', $store->id))
            ->with(['fromStore', 'toStore'])
            ->withCount('items');

        return $this->paginated(
            $this->tableQuery(
                $query,
                $request,
                searchable: ['transfer_number'],
                sortable: ['created_at'],
                defaultSort: 'created_at',
            ),
            StockTransferResource::class,
        );
    }

    /** Readable by anyone who can see either end. */
    public function show(StockTransfer $transfer): JsonResponse
    {
        abort_unless(
            $this->tenantMay('view', $transfer->fromStore) || $this->tenantMay('view', $transfer->toStore),
            403,
            'This action is not available to you.',
        );

        return $this->ok(StockTransferResource::make(
            $transfer->load(['items.medicine', 'items.sourceBatch', 'items.destinationBatch', 'fromStore', 'toStore'])
        ));
    }

    /** A repeated Idempotency-Key answers 200 with the first transfer. */
    public function store(StoreStockTransferRequest $request): JsonResponse
    {
        $data = $request->validated();

        $from = PharmacyStore::query()->findOrFail($data['from_store_id']);
        $to = PharmacyStore::query()->findOrFail($data['to_store_id']);

        $this->authorizeTenant('transfer', $from);
        $this->authorizeTenant('transfer', $to);

        [$transfer, $created] = $this->transfers->transfer(
            $from,
            $to,
            $data['items'],
            $data['notes'] ?? null,
            $data['idempotency_key'],
        );

        $resource = StockTransferResource::make($transfer);

        return $created
            ? $this->created($resource, "{$transfer->transfer_number} completed")
            : $this->ok($resource, "{$transfer->transfer_number} was already completed");
    }
}
