<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\Api\V1\Tenant\StoreAppointmentRequest;
use App\Http\Resources\Tenant\AppointmentResource;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Doctor;
use App\Services\Opd\BookingService;
use App\Services\Opd\OpdBoard;
use App\Services\Opd\VisitWorkflow;
use App\Services\Tenancy\TenantBranchAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The OPD queue: booking into it, walking into it, and moving through it.
 *
 * THE DESK'S CONTROLLER, and only the desk's. Booking, checking in, calling
 * through, cancelling, no-shows. Starting and completing a consultation used
 * to live here too and are now ConsultationController's, behind the doctor's
 * own capabilities and the check that the visit is theirs — which is the
 * whole separation this workflow exists to make.
 *
 * The branch check is about *which* branch rather than about seniority; what
 * somebody may do is the route's `permission:` middleware.
 */
class AppointmentController extends BaseApiController
{
    public function __construct(
        private readonly BookingService $booking,
        private readonly VisitWorkflow $workflow,
        private readonly TenantBranchAccess $branches,
    ) {}

    /**
     * One branch's day in arrival order, optionally narrowed to one doctor.
     *
     * `doctor_id` is a filter, not a requirement. It used to be required, and
     * that made the screen unusable for its main job: somebody at the desk is
     * looking for a patient, and having to guess which of six doctors they
     * belong to before the list appears is the wrong question.
     *
     * Booked patients are not floated to the top: their slot is shown so the
     * desk can call somebody out of turn deliberately, which is a person's
     * decision rather than a rule applied behind their back.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'doctor_id' => ['nullable', 'integer'],
            'location_id' => ['required', 'integer'],
            'date' => ['required', 'date'],
        ]);

        $locationId = (int) $request->input('location_id');

        $this->assertBranch($locationId);

        $queue = $this->booking->queue(
            $request->filled('doctor_id') ? (int) $request->input('doctor_id') : null,
            $locationId,
            Carbon::parse($request->input('date')),
        );

        return $this->ok([
            'queue' => AppointmentResource::collection($queue),

            /*
             * Waiting is now strictly "nobody has called them": the people
             * whose wait is still growing and who the desk has to do
             * something about. Called is its own figure, and a number that
             * stays up is a specific, fixable problem — somebody was called
             * and has not gone in.
             */
            'waiting' => $queue->where('queue_status', Appointment::QUEUE_WAITING)->count(),
            'called' => $queue->where('queue_status', Appointment::QUEUE_CALLED)->count(),

            'with_doctor' => $queue->where('status', Appointment::STATUS_IN_CONSULTATION)->count(),

            // The doctors' output, not closed visits: somebody at the
            // pharmacy has been seen.
            'seen' => $queue->where('consultation_status', Appointment::CONSULT_COMPLETED)->count(),

            'expected' => $queue->where('status', Appointment::STATUS_BOOKED)->count(),

            // Seen, and still here for somebody else's queue.
            'awaiting' => [
                'pharmacy' => $queue->where('status', Appointment::STATUS_AWAITING_PHARMACY)->count(),
                'laboratory' => $queue->where('status', Appointment::STATUS_AWAITING_LAB)->count(),
                'payment' => $queue->where('status', Appointment::STATUS_AWAITING_PAYMENT)->count(),
            ],

            /*
             * The doctors working this branch's day, so the filter is built
             * from the day itself rather than from every doctor on the books —
             * one with nobody booked is not a filter anybody wants.
             *
             * From the WHOLE day, never from the queue above, which may already
             * have been narrowed. Deriving it from a filtered list would leave
             * one doctor in it the moment somebody filtered, and the control
             * would collapse to the option already chosen with no way back.
             */
            'doctors' => $this->booking->doctorsOn(
                $locationId,
                Carbon::parse($request->input('date')),
            ),

            // The same numbers the board uses. Sent rather than hardcoded on
            // the screen so "too long" means one thing across the product.
            'thresholds' => [
                'warn' => OpdBoard::WAIT_WARN,
                'critical' => OpdBoard::WAIT_CRITICAL,
            ],
        ]);
    }

    /** What is still free for a doctor on a date — availability minus bookings. */
    public function slots(Request $request, Doctor $doctor): JsonResponse
    {
        $request->validate([
            'date' => ['required', 'date'],
            'location_id' => ['nullable', 'integer'],
        ]);

        $locationId = $request->filled('location_id')
            ? (int) $request->input('location_id')
            : null;

        $this->assertBranch($locationId);

        return $this->ok([
            'doctor_id' => $doctor->id,
            'date' => $request->date('date')->toDateString(),
            'sessions' => $this->booking->openSlots(
                $doctor,
                Carbon::parse($request->input('date')),
                $locationId,
            ),
        ]);
    }

    public function store(StoreAppointmentRequest $request): JsonResponse
    {
        $data = $request->validated();

        try {
            $appointment = $data['type'] === Appointment::WALK_IN
                ? $this->booking->walkIn($data)
                : $this->booking->book($data);
        } catch (RuntimeException $exception) {
            // A refusal the person at the desk can act on — the slot went, or
            // the doctor is not sitting — not a server fault.
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->created(
            AppointmentResource::make($appointment->load(['customer', 'doctor', 'location'])),
            $data['type'] === Appointment::WALK_IN
                ? "Token {$appointment->token_no} issued"
                : 'Appointment booked',
        );
    }

    /** Arrived. This is where the token is issued, and the queue joined. */
    public function checkIn(Appointment $appointment): JsonResponse
    {
        return $this->move(
            $appointment,
            fn () => $this->booking->checkIn($appointment),
            fn () => "Token {$appointment->token_no} issued",
        );
    }

    /**
     * Call the patient through — the desk's one act on a consultation.
     *
     * NOT "start". It moves the queue from `waiting` to `called` and stops
     * there; the doctor takes it from there under their own capability. The
     * endpoint this replaced was called `start`, did both, and was held by
     * whoever was at reception.
     */
    public function call(Request $request, Appointment $appointment): JsonResponse
    {
        return $this->move(
            $appointment,
            fn () => $this->workflow->call($appointment, $request->user()),
            fn () => $appointment->token_no
                ? "Token {$appointment->token_no} called"
                : 'Patient called',
        );
    }

    public function cancel(Request $request, Appointment $appointment): JsonResponse
    {
        $request->validate(['reason' => ['nullable', 'string', 'max:191']]);

        return $this->move(
            $appointment,
            fn () => $this->booking->cancel($appointment, $request->input('reason')),
            fn () => 'Appointment cancelled',
        );
    }

    public function noShow(Appointment $appointment): JsonResponse
    {
        return $this->move(
            $appointment,
            fn () => $this->booking->markNoShow($appointment),
            fn () => 'Marked as a no-show',
        );
    }

    /**
     * Every status change goes through here.
     *
     * The service refuses a move the state machine does not allow, and that
     * refusal is a 422 rather than a 500: "the patient next to you already
     * called that token" is a thing that happens at a busy desk, not a bug.
     * WorkflowConflict extends RuntimeException, so both are caught here and
     * both carry a message somebody can act on.
     */
    private function move(Appointment $appointment, callable $change, callable $message): JsonResponse
    {
        $this->assertBranch($appointment->location_id);

        try {
            $appointment = $change();
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(
            AppointmentResource::make($appointment->load(['customer', 'doctor', 'location'])),
            $message(),
        );
    }

    /** A branch id from the client is just a number until somebody checks it. */
    private function assertBranch(?int $locationId): void
    {
        if (! $this->branches->currentCanUse($locationId)) {
            abort(403, 'You can only work with the branch you are at.');
        }
    }
}
