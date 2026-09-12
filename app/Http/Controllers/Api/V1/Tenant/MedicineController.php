<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Concerns\HandlesTableQueries;
use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\Api\V1\Tenant\StoreMedicineRequest;
use App\Http\Requests\Api\V1\Tenant\UpdateMedicineRequest;
use App\Http\Resources\Tenant\MedicineResource;
use App\Models\Tenant\EntityFieldSetting;
use App\Models\Tenant\Medicine;
use App\Repositories\Tenant\Contracts\MedicineRepositoryInterface;
use App\Services\Fields\FieldSchema;
use App\Support\Fields\MedicineFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The medicine master.
 *
 * Read by anyone holding `medicines.view` — a doctor searching for what to
 * prescribe. Written only by `medicines.manage`, an organization-scoped
 * capability: changing the catalogue changes what every branch prescribes.
 * Restoring a removed medicine is `pharmacy.restore`. The routes say which.
 */
class MedicineController extends BaseApiController
{
    use HandlesTableQueries;

    private const SEARCHABLE = ['generic_name', 'brand_name', 'medicine_code', 'strength', 'manufacturer'];

    public function __construct(
        private readonly MedicineRepositoryInterface $medicines,
    ) {}

    /** The field definitions the form and table render from. */
    public function fields(FieldSchema $schema): JsonResponse
    {
        return $this->ok(
            array_values($schema->for(EntityFieldSetting::ENTITY_MEDICINE, MedicineFields::all()))
        );
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->medicines->listing(
            status: $request->string('status')->toString() ?: null,
            filters: $request->only(['dosage_form', 'schedule', 'category']),
        );

        return $this->paginated(
            $this->tableQuery(
                $query,
                $request,
                searchable: self::SEARCHABLE,
                sortable: ['generic_name', 'brand_name', 'strength', 'dosage_form', 'manufacturer', 'is_active', 'created_at'],
                defaultSort: 'generic_name',
                defaultDirection: 'asc',
            ),
            MedicineResource::class,
        );
    }

    /** What has been removed, newest first, with who removed it and why. */
    public function removed(Request $request): JsonResponse
    {
        return $this->paginated(
            $this->tableQuery(
                $this->medicines->removed(),
                $request,
                searchable: self::SEARCHABLE,
                sortable: ['generic_name', 'deleted_at'],
                defaultSort: 'deleted_at',
            ),
            MedicineResource::class,
        );
    }

    public function show(Medicine $medicine): JsonResponse
    {
        return $this->ok(MedicineResource::make($medicine));
    }

    public function store(StoreMedicineRequest $request): JsonResponse
    {
        $medicine = $this->medicines->create($request->validated());

        return $this->created(MedicineResource::make($medicine->refresh()), 'Medicine added');
    }

    public function update(UpdateMedicineRequest $request, Medicine $medicine): JsonResponse
    {
        $updated = $this->medicines->update($medicine, $request->validated());

        return $this->ok(MedicineResource::make($updated), 'Medicine updated');
    }

    /**
     * Remove a medicine, with a reason.
     *
     * Deactivating is the everyday tool; removing takes it out of every
     * working screen. From Phase 3 on this also refuses a medicine that
     * any store still holds stock of.
     */
    public function destroy(Request $request, Medicine $medicine): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $medicine->deleteWithReason($validated['reason']);

        return $this->ok(null, 'Medicine removed');
    }

    /**
     * Bring a removed medicine back, with a reason.
     *
     * Refused when a live medicine has taken its identity or its code since
     * — restoring it would create the duplicate the catalogue refuses.
     */
    public function restore(Request $request, Medicine $medicine): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        if (! $medicine->trashed()) {
            return $this->fail('This medicine has not been removed.', 409);
        }

        $clash = $this->medicines->findDuplicate($medicine->only(Medicine::IDENTITY), $medicine->id)
            ?? ($medicine->medicine_code
                ? $this->medicines->findByCode($medicine->medicine_code, $medicine->id)
                : null);

        if ($clash) {
            return $this->fail(
                "{$clash->displayName()} is in the catalogue now with the same details. Edit or remove it before restoring this one.",
                409,
            );
        }

        $medicine->restoreWithReason($validated['reason']);

        return $this->ok(MedicineResource::make($medicine->refresh()), 'Medicine restored');
    }
}
