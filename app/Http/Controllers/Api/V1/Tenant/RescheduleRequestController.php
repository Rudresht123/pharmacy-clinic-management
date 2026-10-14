<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Resources\Tenant\RescheduleRequestResource;
use App\Models\Tenant\AppointmentRescheduleRequest;
use App\Services\Opd\RescheduleRequests;
use App\Services\Tenancy\TenantBranchAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Patients' requests to move a booking — the desk's list and its answers.
 *
 * Behind `appointments.reschedule`. What somebody sees is their own branches;
 * an owner sees every branch.
 *
 * @group Tenant > Appointments & OPD
 */
class RescheduleRequestController extends BaseApiController
{
    public function __construct(
        private readonly RescheduleRequests $requests,
        private readonly TenantBranchAccess $branches,
    ) {}

    /** Pending by default; `status=all` or any one status for the history. */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in([...AppointmentRescheduleRequest::STATUSES, 'all'])],
            'location_id' => ['nullable', 'integer'],
        ]);

        $status = $validated['status'] ?? AppointmentRescheduleRequest::PENDING;

        return $this->ok(RescheduleRequestResource::collection(
            $this->requests->list($this->branchesFor($request), $status === 'all' ? null : $status),
        ));
    }

    /** Just the number waiting — the menu's badge. */
    public function count(Request $request): JsonResponse
    {
        return $this->ok(['pending' => $this->requests->pendingCount($this->branchesFor($request))]);
    }

    /**
     * Yes. Moves the visit to the time asked for, or — when that has gone and
     * the patient agreed another on the phone — to `appointment_date` /
     * `slot_at` / `location_id` given here.
     */
    public function approve(Request $request, AppointmentRescheduleRequest $rescheduleRequest): JsonResponse
    {
        $validated = $request->validate([
            'appointment_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today', 'required_with:slot_at'],
            'slot_at' => ['nullable', 'date_format:H:i', 'required_with:appointment_date'],
            'location_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $this->assertBranch($rescheduleRequest->location_id);

        if (isset($validated['location_id'])) {
            $this->assertBranch((int) $validated['location_id']);
        }

        try {
            $answered = $this->requests->approve(
                $request->attributes->get('tenant.organization'),
                $request->user(),
                $rescheduleRequest,
                $validated['appointment_date'] ?? null,
                $validated['slot_at'] ?? null,
                isset($validated['location_id']) ? (int) $validated['location_id'] : null,
                $validated['note'] ?? null,
            );
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok($this->shape($answered), 'Rescheduled. The patient has been told.');
    }

    /** No. The visit stays where it was; `note` tells the patient why. */
    public function reject(Request $request, AppointmentRescheduleRequest $rescheduleRequest): JsonResponse
    {
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        $this->assertBranch($rescheduleRequest->location_id);

        try {
            $answered = $this->requests->reject(
                $request->attributes->get('tenant.organization'),
                $request->user(),
                $rescheduleRequest,
                $validated['note'] ?? null,
            );
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok($this->shape($answered), 'Request rejected. The patient has been told.');
    }

    private function shape(AppointmentRescheduleRequest $request): RescheduleRequestResource
    {
        return RescheduleRequestResource::make(
            $request->load(['customer', 'doctor', 'location', 'requestedLocation', 'decider']),
        );
    }

    /** @return list<int>|null null: every branch */
    private function branchesFor(Request $request): ?array
    {
        if ($request->filled('location_id')) {
            $locationId = (int) $request->input('location_id');
            $this->assertBranch($locationId);

            return [$locationId];
        }

        return $this->branches->allowed($request->user());
    }

    private function assertBranch(?int $locationId): void
    {
        if (! $this->branches->currentCanUse($locationId)) {
            abort(403, 'You can only work with the branch you are at.');
        }
    }
}
