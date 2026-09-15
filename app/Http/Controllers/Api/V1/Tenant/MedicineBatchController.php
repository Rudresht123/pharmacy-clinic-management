<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Concerns\AuthorizesTenantUser;
use App\Http\Concerns\HandlesTableQueries;
use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Resources\Tenant\MedicineBatchResource;
use App\Models\Tenant\Medicine;
use App\Models\Tenant\MedicineBatch;
use App\Models\Tenant\PharmacyStore;
use App\Models\Tenant\StoreMedicine;
use App\Services\Pharmacy\Inventory\BatchService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Validation\Rule;

/**
 * A store's stock: per medicine, per batch, and each batch's standing.
 *
 * Reading follows `view` on the store (PharmacyStorePolicy); acting on a
 * batch follows MedicineBatchPolicy, asked about the batch's store's branch.
 */
class MedicineBatchController extends BaseApiController
{
    use AuthorizesTenantUser, HandlesTableQueries;

    public function __construct(
        private readonly BatchService $batches,
    ) {}

    /**
     * Stock per medicine: on hand, how much of it can actually be dispensed,
     * the next expiry, and whether it is at or below its reorder level.
     */
    public function stock(Request $request, PharmacyStore $store): JsonResponse
    {
        $this->authorizeTenant('view', $store);

        $today = now()->toDateString();
        $term = trim((string) $request->query('search', ''));

        $page = MedicineBatch::query()
            ->where('pharmacy_store_id', $store->id)
            ->selectRaw('medicine_id, sum(quantity_available) as on_hand')
            ->selectRaw(
                "sum(case when status = 'active' and expiry_date > ? then quantity_available else 0 end) as usable",
                [$today],
            )
            ->selectRaw(
                "min(case when status = 'active' and quantity_available > 0 and expiry_date > ? then expiry_date end) as next_expiry",
                [$today],
            )
            ->when($term !== '', fn (Builder $query) => $query->whereHas(
                'medicine',
                fn (Builder $medicine) => $medicine->withTrashed()->where(
                    fn (Builder $names) => $names
                        ->where('generic_name', 'ILIKE', "%{$term}%")
                        ->orWhere('brand_name', 'ILIKE', "%{$term}%")
                ),
            ))
            ->groupBy('medicine_id')
            ->orderBy('medicine_id')
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        $ids = collect($page->items())->pluck('medicine_id')->all();

        $medicines = Medicine::withTrashed()->whereIn('id', $ids)->get()->keyBy('id');
        $levels = StoreMedicine::query()
            ->where('pharmacy_store_id', $store->id)
            ->whereIn('medicine_id', $ids)
            ->get()
            ->keyBy('medicine_id');

        $page->through(function (MedicineBatch $row) use ($medicines, $levels) {
            $medicine = $medicines->get($row->medicine_id);
            $level = $levels->get($row->medicine_id);
            $usable = (int) $row->usable;

            return [
                'medicine_id' => $row->medicine_id,
                'medicine_name' => $medicine?->displayName(),
                'base_unit' => $medicine?->base_unit,
                'on_hand' => (int) $row->on_hand,
                'usable' => $usable,
                'next_expiry' => $row->next_expiry,
                'reorder_level' => $level?->reorder_level,
                // At or below the reorder level counts as low.
                'is_low' => $level !== null && $usable <= $level->reorder_level,
            ];
        });

        return $this->paginated($page, JsonResource::class);
    }

    public function index(Request $request, PharmacyStore $store): JsonResponse
    {
        $this->authorizeTenant('view', $store);

        $term = trim((string) $request->query('search', ''));

        $query = MedicineBatch::query()
            ->where('pharmacy_store_id', $store->id)
            ->with(['medicine', 'supplier'])
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('medicine_id'), fn (Builder $q) => $q->where('medicine_id', (int) $request->input('medicine_id')))
            ->when($request->boolean('in_stock'), fn (Builder $q) => $q->where('quantity_available', '>', 0))
            ->when(
                $request->filled('expiring_within'),
                fn (Builder $q) => $q->whereDate('expiry_date', '<=', now()->addDays((int) $request->input('expiring_within'))->toDateString())
            )
            ->when($term !== '', fn (Builder $q) => $q->where(
                fn (Builder $inner) => $inner
                    ->where('batch_number', 'ILIKE', "%{$term}%")
                    ->orWhereHas('medicine', fn (Builder $medicine) => $medicine->withTrashed()->where(
                        fn (Builder $names) => $names
                            ->where('generic_name', 'ILIKE', "%{$term}%")
                            ->orWhere('brand_name', 'ILIKE', "%{$term}%")
                    ))
            ));

        return $this->paginated(
            $this->tableQuery(
                $query,
                $request,
                sortable: ['expiry_date', 'quantity_available', 'batch_number', 'received_date', 'created_at'],
                defaultSort: 'expiry_date',
                defaultDirection: 'asc',
            ),
            MedicineBatchResource::class,
        );
    }

    public function show(MedicineBatch $batch): JsonResponse
    {
        $this->authorizeTenant('view', $batch);

        return $this->ok(MedicineBatchResource::make($batch->load(['medicine', 'supplier', 'store'])));
    }

    /** Block, recall or unblock, with a reason. */
    public function updateStatus(Request $request, MedicineBatch $batch): JsonResponse
    {
        $this->authorizeTenant('changeStatus', $batch);

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in([MedicineBatch::BLOCKED, MedicineBatch::RECALLED, MedicineBatch::ACTIVE])],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $updated = $this->batches->changeStatus($batch, $validated['status'], $validated['reason']);

        return $this->ok(MedicineBatchResource::make($updated->load(['medicine', 'supplier'])), 'Batch updated');
    }

    public function destroy(Request $request, MedicineBatch $batch): JsonResponse
    {
        $this->authorizeTenant('delete', $batch);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $this->batches->remove($batch, $validated['reason']);

        return $this->ok(null, 'Batch removed');
    }

    public function restore(Request $request, MedicineBatch $batch): JsonResponse
    {
        $this->authorizeTenant('restore', $batch);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $restored = $this->batches->restore($batch, $validated['reason']);

        return $this->ok(MedicineBatchResource::make($restored->load(['medicine', 'supplier'])), 'Batch restored');
    }
}
