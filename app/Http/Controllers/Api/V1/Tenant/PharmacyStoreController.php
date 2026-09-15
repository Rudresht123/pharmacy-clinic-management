<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Concerns\AuthorizesTenantUser;
use App\Http\Concerns\HandlesTableQueries;
use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\Api\V1\Tenant\StorePharmacyStoreRequest;
use App\Http\Requests\Api\V1\Tenant\UpdatePharmacyStoreRequest;
use App\Http\Resources\Tenant\PharmacyStoreResource;
use App\Models\Tenant\Location;
use App\Models\Tenant\PharmacyStore;
use App\Models\Tenant\User;
use App\Repositories\Tenant\Contracts\PharmacyStoreRepositoryInterface;
use App\Services\Pharmacy\PharmacyStores;
use App\Services\Tenancy\TenantBranchAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Pharmacy stores.
 *
 * The route says which capability each action needs at the acting branch;
 * PharmacyStorePolicy then asks the same question about the store's own
 * branch. The rules about default stores live in PharmacyStores.
 */
class PharmacyStoreController extends BaseApiController
{
    use AuthorizesTenantUser, HandlesTableQueries;

    private const SEARCHABLE = ['name', 'code'];

    public function __construct(
        private readonly PharmacyStoreRepositoryInterface $stores,
        private readonly PharmacyStores $service,
        private readonly TenantBranchAccess $branches,
    ) {}

    /**
     * The branches a store can be added at and the people who can run one,
     * for the form — limited to the caller's own reach, so the form needs no
     * capability beyond the one that opens it.
     */
    public function formOptions(): JsonResponse
    {
        $mine = $this->branches->allowed(Auth::guard('web')->user());

        $branches = Location::query()
            ->active()
            ->when($mine !== null, fn (Builder $query) => $query->whereIn('id', $mine ?: [0]))
            ->orderBy('name')
            ->get(['id', 'name', 'drug_license_no', 'drug_license_expiry_date']);

        $people = User::query()
            ->where('is_active', true)
            ->when($mine !== null, fn (Builder $query) => $query->whereHas(
                'memberships',
                fn (Builder $membership) => $membership->whereIn('location_id', $mine ?: [0]),
            ))
            ->orderBy('name')
            ->get(['id', 'name']);

        return $this->ok([
            'branches' => $branches->map(fn (Location $branch) => [
                'id' => $branch->id,
                'name' => $branch->name,
                'drug_license_no' => $branch->drug_license_no,
            ])->all(),
            'pharmacists' => $people->map(fn (User $person) => [
                'id' => $person->id,
                'name' => $person->name,
            ])->all(),
            'store_types' => PharmacyStore::TYPES,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->stores->listing(
            status: $request->string('status')->toString() ?: null,
            locationId: $request->filled('location_id') ? (int) $request->input('location_id') : null,
            storeType: $request->string('store_type')->toString() ?: null,
        );

        // The dropdown case — every operational store the caller can use.
        if ($request->boolean('all')) {
            return $this->ok(PharmacyStoreResource::collection(
                $query->operational()->orderByDesc('is_default')->orderBy('name')->get()
            ));
        }

        return $this->paginated(
            $this->tableQuery(
                $query,
                $request,
                searchable: self::SEARCHABLE,
                sortable: ['name', 'code', 'store_type', 'is_active', 'created_at'],
                defaultSort: 'name',
                defaultDirection: 'asc',
            ),
            PharmacyStoreResource::class,
        );
    }

    public function removed(Request $request): JsonResponse
    {
        return $this->paginated(
            $this->tableQuery(
                $this->stores->removed(),
                $request,
                searchable: self::SEARCHABLE,
                sortable: ['name', 'deleted_at'],
                defaultSort: 'deleted_at',
            ),
            PharmacyStoreResource::class,
        );
    }

    public function show(PharmacyStore $store): JsonResponse
    {
        $this->authorizeTenant('view', $store);

        return $this->ok(PharmacyStoreResource::make(
            $store->load(['location', 'pharmacist'])->loadCount('storeMedicines')
        ));
    }

    public function store(StorePharmacyStoreRequest $request): JsonResponse
    {
        $data = $request->validated();

        $this->authorizeTenant('create', [PharmacyStore::class, (int) $data['location_id']]);

        $store = $this->service->create($data);

        return $this->created(PharmacyStoreResource::make($store->load(['location', 'pharmacist'])), 'Store added');
    }

    public function update(UpdatePharmacyStoreRequest $request, PharmacyStore $store): JsonResponse
    {
        $this->authorizeTenant('update', $store);

        $updated = $this->service->update($store, $request->validated());

        return $this->ok(PharmacyStoreResource::make($updated->load(['location', 'pharmacist'])), 'Store updated');
    }

    /** Remove a store, with a reason. Refused (409) while it holds stock. */
    public function destroy(Request $request, PharmacyStore $store): JsonResponse
    {
        $this->authorizeTenant('delete', $store);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $this->service->remove($store, $validated['reason']);

        return $this->ok(null, 'Store removed');
    }

    public function restore(Request $request, PharmacyStore $store): JsonResponse
    {
        $this->authorizeTenant('restore', $store);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        if (! $store->trashed()) {
            return $this->fail('This store has not been removed.', 409);
        }

        $restored = $this->service->restore($store, $validated['reason']);

        return $this->ok(PharmacyStoreResource::make($restored->load(['location', 'pharmacist'])), 'Store restored');
    }
}
