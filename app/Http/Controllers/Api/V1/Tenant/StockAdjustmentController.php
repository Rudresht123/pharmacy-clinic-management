<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Concerns\AuthorizesTenantUser;
use App\Http\Concerns\HandlesTableQueries;
use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\Api\V1\Tenant\StoreStockAdjustmentRequest;
use App\Http\Resources\Tenant\StockAdjustmentResource;
use App\Models\Tenant\MedicineBatch;
use App\Models\Tenant\PharmacyStore;
use App\Models\Tenant\StockAdjustment;
use App\Services\Pharmacy\Inventory\StockAdjustmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Stock adjustments at a store: damage, write-offs, count corrections.
 */
class StockAdjustmentController extends BaseApiController
{
    use AuthorizesTenantUser, HandlesTableQueries;

    public function __construct(
        private readonly StockAdjustmentService $adjustments,
    ) {}

    public function index(Request $request, PharmacyStore $store): JsonResponse
    {
        $this->authorizeTenant('view', $store);

        return $this->paginated(
            $this->tableQuery(
                StockAdjustment::query()->where('pharmacy_store_id', $store->id)->with(['batch', 'medicine']),
                $request,
                searchable: ['adjustment_number', 'reason'],
                sortable: ['created_at', 'quantity'],
                defaultSort: 'created_at',
            ),
            StockAdjustmentResource::class,
        );
    }

    /** A repeated Idempotency-Key answers 200 with the first adjustment. */
    public function store(StoreStockAdjustmentRequest $request, PharmacyStore $store): JsonResponse
    {
        $this->authorizeTenant('adjust', $store);

        $data = $request->validated();

        $batch = MedicineBatch::query()->findOrFail($data['medicine_batch_id']);

        if ($batch->pharmacy_store_id !== $store->id) {
            throw ValidationException::withMessages([
                'medicine_batch_id' => 'That batch is not in this store.',
            ]);
        }

        [$adjustment, $created] = $this->adjustments->adjust($batch, $data, $data['idempotency_key']);

        $resource = StockAdjustmentResource::make($adjustment);

        return $created
            ? $this->created($resource, "{$adjustment->adjustment_number} recorded")
            : $this->ok($resource, "{$adjustment->adjustment_number} was already recorded");
    }
}
