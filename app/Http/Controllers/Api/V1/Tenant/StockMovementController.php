<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Concerns\AuthorizesTenantUser;
use App\Http\Concerns\HandlesTableQueries;
use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Resources\Tenant\StockMovementResource;
use App\Models\Tenant\PharmacyStore;
use App\Models\Tenant\StockMovement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A store's ledger, newest first. Read-only: nothing here, or anywhere,
 * edits a movement.
 */
class StockMovementController extends BaseApiController
{
    use AuthorizesTenantUser, HandlesTableQueries;

    public function index(Request $request, PharmacyStore $store): JsonResponse
    {
        $this->authorizeTenant('view', $store);

        $query = StockMovement::query()
            ->where('pharmacy_store_id', $store->id)
            ->with(['medicine', 'batch'])
            ->when($request->filled('medicine_id'), fn (Builder $q) => $q->where('medicine_id', (int) $request->input('medicine_id')))
            ->when($request->filled('batch_id'), fn (Builder $q) => $q->where('medicine_batch_id', (int) $request->input('batch_id')))
            ->when($request->filled('movement_type'), fn (Builder $q) => $q->where('movement_type', $request->string('movement_type')->toString()))
            ->when($request->filled('from'), fn (Builder $q) => $q->whereDate('movement_date', '>=', $request->date('from')->toDateString()))
            ->when($request->filled('to'), fn (Builder $q) => $q->whereDate('movement_date', '<=', $request->date('to')->toDateString()));

        return $this->paginated(
            $this->tableQuery(
                $query,
                $request,
                sortable: ['movement_date', 'id'],
                defaultSort: 'id',
            ),
            StockMovementResource::class,
        );
    }
}
