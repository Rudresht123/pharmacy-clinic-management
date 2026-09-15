<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Concerns\AuthorizesTenantUser;
use App\Http\Concerns\HandlesTableQueries;
use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\Api\V1\Tenant\SavePrescriptionRequest;
use App\Http\Resources\Tenant\PrescriptionResource;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\PharmacyStore;
use App\Models\Tenant\Prescription;
use App\Services\Permissions\Permission;
use App\Services\Pharmacy\MedicineAvailability;
use App\Services\Prescriptions\PrescriptionService;
use App\Services\Tenancy\TenantBranchAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Prescriptions.
 *
 * The route holds the capability at the acting branch; PrescriptionPolicy
 * asks about the prescription's own branch and, for writing, whether this
 * is the doctor who saw the patient. PrescriptionService decides whether the
 * prescription's state allows the change.
 */
class PrescriptionController extends BaseApiController
{
    use AuthorizesTenantUser, HandlesTableQueries;

    private const WITH = ['items.medicine', 'patient', 'doctor', 'location', 'canceller'];

    public function __construct(
        private readonly PrescriptionService $prescriptions,
        private readonly MedicineAvailability $availability,
        private readonly TenantBranchAccess $branches,
        private readonly Permission $permissions,
    ) {}

    /** Prescriptions at the branches the caller works at. */
    public function index(Request $request): JsonResponse
    {
        $mine = $this->branches->allowed(Auth::guard('web')->user());

        $query = Prescription::query()
            ->with(['patient', 'doctor', 'location'])
            ->withCount('items')
            ->when($mine !== null, fn (Builder $q) => $q->whereIn('location_id', $mine ?: [0]))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('customer_id'), fn (Builder $q) => $q->where('customer_id', (int) $request->input('customer_id')))
            ->when($request->filled('doctor_id'), fn (Builder $q) => $q->where('doctor_id', (int) $request->input('doctor_id')))
            ->when($request->filled('location_id'), fn (Builder $q) => $q->where('location_id', (int) $request->input('location_id')));

        return $this->paginated(
            $this->tableQuery(
                $query,
                $request,
                searchable: ['prescription_number'],
                sortable: ['prescription_date', 'prescription_number', 'status', 'created_at'],
                defaultSort: 'created_at',
            ),
            PrescriptionResource::class,
        );
    }

    public function show(Prescription $prescription): JsonResponse
    {
        $this->authorizeTenant('view', $prescription);

        return $this->ok(PrescriptionResource::make($prescription->load(self::WITH)));
    }

    /**
     * The visit's live prescription, if one has been started, with the store
     * it would be dispensed from and what that store holds of each line.
     */
    public function forAppointment(Request $request, Appointment $appointment): JsonResponse
    {
        $this->authorizeTenant('viewVisit', [Prescription::class, $appointment]);

        $prescription = Prescription::query()
            ->live()
            ->where('appointment_id', $appointment->id)
            ->with(self::WITH)
            ->first();

        $store = $this->storeFor($request, $appointment->location_id);
        $medicineIds = $prescription?->items->pluck('medicine_id')->filter()->all() ?? [];

        return $this->ok([
            'appointment_id' => $appointment->id,
            'prescription' => $prescription ? PrescriptionResource::make($prescription) : null,
            'store' => $store ? ['id' => $store->id, 'name' => $store->name] : null,
            'availability' => $store ? array_values($this->availability->atStore($store, $medicineIds)) : [],
        ]);
    }

    public function store(SavePrescriptionRequest $request): JsonResponse
    {
        $data = $request->validated();

        $appointment = Appointment::query()->findOrFail($data['appointment_id']);

        $this->authorizeTenant('create', [Prescription::class, $appointment]);

        $prescription = $this->prescriptions->create($appointment, $data);

        return $this->created(
            PrescriptionResource::make($prescription->load(self::WITH)),
            "{$prescription->prescription_number} started",
        );
    }

    public function update(SavePrescriptionRequest $request, Prescription $prescription): JsonResponse
    {
        $this->authorizeTenant('update', $prescription);

        $updated = $this->prescriptions->update($prescription, $request->validated());

        return $this->ok(PrescriptionResource::make($updated->load(self::WITH)), 'Prescription saved');
    }

    public function issue(Request $request, Prescription $prescription): JsonResponse
    {
        $this->authorizeTenant('issue', $prescription);

        $validated = $request->validate(['valid_until' => ['nullable', 'date', 'after_or_equal:today']]);

        $issued = $this->prescriptions->issue($prescription, $validated['valid_until'] ?? null);

        return $this->ok(
            PrescriptionResource::make($issued->load(self::WITH)),
            "{$issued->prescription_number} issued",
        );
    }

    public function cancel(Request $request, Prescription $prescription): JsonResponse
    {
        $this->authorizeTenant('cancel', $prescription);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $cancelled = $this->prescriptions->cancel($prescription, $validated['reason']);

        return $this->ok(
            PrescriptionResource::make($cancelled->load(self::WITH)),
            "{$cancelled->prescription_number} cancelled",
        );
    }

    /** Remove a draft, with a reason. */
    public function destroy(Request $request, Prescription $prescription): JsonResponse
    {
        $this->authorizeTenant('delete', $prescription);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $this->prescriptions->remove($prescription, $validated['reason']);

        return $this->ok(null, 'Draft removed');
    }

    /**
     * Where this branch dispenses from — only when the pharmacy module runs
     * there, since otherwise there is no stock to speak of.
     */
    private function storeFor(Request $request, int $locationId): ?PharmacyStore
    {
        $organization = $request->attributes->get('tenant.organization');

        if (! $organization || ! $this->permissions->hasModule($organization, 'pharmacy', $locationId)) {
            return null;
        }

        return $this->availability->storeFor($locationId);
    }
}
