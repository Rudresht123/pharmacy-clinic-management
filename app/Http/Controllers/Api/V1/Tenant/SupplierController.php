<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Concerns\HandlesTableQueries;
use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\Api\V1\Tenant\SaveSupplierRequest;
use App\Http\Resources\Tenant\SupplierResource;
use App\Models\Tenant\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Suppliers: who goods come from.
 *
 * Organization-wide — every branch buys from the same list — so there is no
 * store Policy here: the route's capability is the whole question. Reading
 * is `pharmacy.view`, changing the list is `pharmacy.stores`, restoring is
 * `pharmacy.restore`.
 */
class SupplierController extends BaseApiController
{
    use HandlesTableQueries;

    private const SEARCHABLE = ['name', 'code', 'gstin', 'phone', 'contact_person'];

    public function index(Request $request): JsonResponse
    {
        $query = Supplier::query()
            ->when($request->string('status')->toString() === 'active', fn (Builder $q) => $q->where('is_active', true))
            ->when($request->string('status')->toString() === 'inactive', fn (Builder $q) => $q->where('is_active', false));

        // The dropdown case: every active supplier, by name.
        if ($request->boolean('all')) {
            return $this->ok(SupplierResource::collection(
                $query->where('is_active', true)->orderBy('name')->get()
            ));
        }

        return $this->paginated(
            $this->tableQuery(
                $query,
                $request,
                searchable: self::SEARCHABLE,
                sortable: ['name', 'code', 'is_active', 'created_at'],
                defaultSort: 'name',
                defaultDirection: 'asc',
            ),
            SupplierResource::class,
        );
    }

    public function removed(Request $request): JsonResponse
    {
        return $this->paginated(
            $this->tableQuery(
                Supplier::onlyTrashed()->with('remover'),
                $request,
                searchable: self::SEARCHABLE,
                sortable: ['name', 'deleted_at'],
                defaultSort: 'deleted_at',
            ),
            SupplierResource::class,
        );
    }

    public function show(Supplier $supplier): JsonResponse
    {
        return $this->ok(SupplierResource::make($supplier));
    }

    public function store(SaveSupplierRequest $request): JsonResponse
    {
        $supplier = Supplier::create($request->validated());

        return $this->created(SupplierResource::make($supplier->refresh()), 'Supplier added');
    }

    public function update(SaveSupplierRequest $request, Supplier $supplier): JsonResponse
    {
        $supplier->update($request->validated());

        return $this->ok(SupplierResource::make($supplier->refresh()), 'Supplier updated');
    }

    /** Batches and receipts keep naming a removed supplier; nothing is erased. */
    public function destroy(Request $request, Supplier $supplier): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $supplier->deleteWithReason($validated['reason']);

        return $this->ok(null, 'Supplier removed');
    }

    public function restore(Request $request, Supplier $supplier): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        if (! $supplier->trashed()) {
            return $this->fail('This supplier has not been removed.', 409);
        }

        foreach (['code', 'gstin'] as $column) {
            $taken = $supplier->{$column} !== null && Supplier::query()
                ->where($column, $supplier->{$column})
                ->whereKeyNot($supplier->id)
                ->exists();

            if ($taken) {
                return $this->fail(
                    "Another supplier now has the same {$column}. Change it there before restoring this one.",
                    409,
                );
            }
        }

        $supplier->getConnection()->transaction(fn () => $supplier->restoreWithReason($validated['reason']));

        return $this->ok(SupplierResource::make($supplier->refresh()), 'Supplier restored');
    }
}
