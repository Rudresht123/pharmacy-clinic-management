<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Resources\Tenant\AppointmentResource;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Doctor;
use App\Services\Opd\QueueSteps;
use App\Services\Opd\VisitWorkflow;
use App\Services\Queue\QueueManagementService;
use App\Services\Tenancy\TenantBranchAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Driving one doctor's queue.
 *
 * Each action checks three things before it moves anything: the branch is one
 * this person works at, the step is theirs at this clinic (QueueSteps), and the
 * queue is one they may drive. A doctor's login gets a 403 on anybody else's.
 *
 * A refusal the state explains — nobody waiting, somebody already called — is a
 * 422 with the service's own sentence, because at a busy desk that is an
 * ordinary thing to hear, not a fault.
 *
 * @group Tenant > Appointments & OPD
 */
class QueueActionController extends BaseApiController
{
    public function __construct(
        private readonly QueueManagementService $queue,
        private readonly VisitWorkflow $workflow,
        private readonly QueueSteps $steps,
        private readonly TenantBranchAccess $branches,
    ) {}

    /** Call whoever is next for THIS doctor. There is no call-next for a branch. */
    public function callNext(Request $request, Doctor $doctor): JsonResponse
    {
        $locationId = $this->location($request);

        $this->steps->authorizeForDoctor($request->user(), QueueSteps::CALL, (int) $doctor->id, $locationId);

        return $this->act(
            fn () => $this->queue->callNext((int) $doctor->id, $locationId, $request->user()),
            fn (Appointment $visit) => "Token {$visit->tokenLabel()} called",
        );
    }

    public function pause(Request $request, Doctor $doctor): JsonResponse
    {
        $locationId = $this->location($request);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:191'],
            'minutes' => ['nullable', 'integer', 'min:1', 'max:720'],
        ]);

        $this->steps->authorizeQueue($request->user(), (int) $doctor->id, $locationId);

        try {
            $this->queue->pause(
                (int) $doctor->id,
                $locationId,
                $data['reason'] ?? null,
                isset($data['minutes']) ? Carbon::now()->addMinutes((int) $data['minutes']) : null,
                $request->user(),
            );
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(null, 'Queue paused');
    }

    public function resume(Request $request, Doctor $doctor): JsonResponse
    {
        $locationId = $this->location($request);

        $this->steps->authorizeQueue($request->user(), (int) $doctor->id, $locationId);

        try {
            $this->queue->resume((int) $doctor->id, $locationId, $request->user());
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(null, 'Queue resumed');
    }

    public function skip(Request $request, Appointment $appointment): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:191']]);

        $this->assertMayDrive($request, $appointment);

        return $this->act(
            fn () => $this->workflow->skip($appointment, $data['reason'] ?? null, $request->user()),
            fn (Appointment $visit) => "Token {$visit->tokenLabel()} skipped",
        );
    }

    /** Announce them again, or bring them back from being skipped. */
    public function recall(Request $request, Appointment $appointment): JsonResponse
    {
        $this->assertMayDrive($request, $appointment);

        return $this->act(
            fn () => $this->workflow->recall($appointment, $request->user()),
            fn (Appointment $visit) => "Token {$visit->tokenLabel()} recalled",
        );
    }

    public function requeue(Request $request, Appointment $appointment): JsonResponse
    {
        $this->assertMayDrive($request, $appointment);

        return $this->act(
            fn () => $this->workflow->requeue($appointment, $request->user()),
            fn (Appointment $visit) => "Token {$visit->tokenLabel()} is back in the queue",
        );
    }

    public function absent(Request $request, Appointment $appointment): JsonResponse
    {
        $this->assertMayDrive($request, $appointment);

        return $this->act(
            fn () => $this->workflow->markAbsent($appointment, $request->user()),
            fn (Appointment $visit) => "Token {$visit->tokenLabel()} marked as gone",
        );
    }

    /** The reason is required by the service as well as here: it is the point. */
    public function priority(Request $request, Appointment $appointment): JsonResponse
    {
        $data = $request->validate([
            'priority' => ['required', Rule::in(Appointment::PRIORITIES)],
            'reason' => ['required', 'string', 'max:191'],
        ]);

        $this->assertBranch($appointment->location_id);
        $this->steps->authorizeQueue($request->user(), (int) $appointment->doctor_id, (int) $appointment->location_id);

        return $this->act(
            fn () => $this->queue->setPriority($appointment, $data['priority'], $data['reason'], $request->user()),
            fn (Appointment $visit) => "Token {$visit->tokenLabel()} is now {$visit->queue_priority}",
        );
    }

    /**
     * Move a waiting patient to another doctor at this branch.
     *
     * Says so if the visit already has a bill: that bill still names the first
     * doctor's fee, and only somebody at billing should change it.
     */
    public function reassign(Request $request, Appointment $appointment): JsonResponse
    {
        $data = $request->validate([
            'doctor_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:191'],
        ]);

        $this->assertBranch($appointment->location_id);
        $this->steps->authorizeQueue($request->user(), (int) $appointment->doctor_id, (int) $appointment->location_id);

        try {
            $result = $this->queue->reassign($appointment, (int) $data['doctor_id'], $data['reason'], $request->user());
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        $visit = $result['visit'];

        return $this->ok(
            AppointmentResource::make($visit->load(['customer', 'doctor', 'location'])),
            "Moved to {$visit->doctor?->name} as token {$visit->tokenLabel()}"
                .($result['billed'] ? '. This visit already has a bill at the first doctor\'s fee: check it at billing.' : ''),
        );
    }

    /**
     * @param  callable(): Appointment  $move
     * @param  callable(Appointment): string  $message
     */
    private function act(callable $move, callable $message): JsonResponse
    {
        try {
            $visit = $move();
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(
            AppointmentResource::make($visit->load(['customer', 'doctor', 'location'])),
            $message($visit),
        );
    }

    private function assertMayDrive(Request $request, Appointment $appointment): void
    {
        $this->assertBranch($appointment->location_id);

        // Calling, skipping and recalling are all the same step at the door.
        $this->steps->authorize($request->user(), QueueSteps::CALL, $appointment);
    }

    private function location(Request $request): int
    {
        $data = $request->validate(['location_id' => ['required', 'integer']]);

        $this->assertBranch((int) $data['location_id']);

        return (int) $data['location_id'];
    }

    private function assertBranch(?int $locationId): void
    {
        if (! $this->branches->currentCanUse($locationId)) {
            abort(403, 'You can only work with the branch you are at.');
        }
    }
}
