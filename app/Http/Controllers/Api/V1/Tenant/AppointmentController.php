<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\Api\V1\Tenant\StoreAppointmentRequest;
use App\Http\Resources\Tenant\AppointmentResource;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Doctor;
use App\Services\Opd\BookingService;
use App\Services\Opd\OpdBoard;
use App\Services\Tenancy\TenantBranchAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The OPD queue: booking into it, walking into it, and moving through it.
 *
 * Open to anyone signed in — whoever is at the desk does all of this. Only
 * the branch check narrows it, and that is about *which* branch rather than
 * about seniority.
 */
class AppointmentController extends BaseApiController
{
    public function __construct(
        private readonly BookingService $booking,
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
            'waiting' => $queue->where('status', Appointment::STATUS_CHECKED_IN)->count(),
            'with_doctor' => $queue->where('status', Appointment::STATUS_IN_CONSULTATION)->count(),
            'seen' => $queue->where('status', Appointment::STATUS_COMPLETED)->count(),
            'expected' => $queue->where('status', Appointment::STATUS_BOOKED)->count(),

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

    /** Arrived. This is where the token is issued. */
    public function checkIn(Appointment $appointment): JsonResponse
    {
        return $this->move(
            $appointment,
            fn () => $this->booking->checkIn($appointment),
            fn () => "Token {$appointment->token_no} issued",
        );
    }

    public function start(Appointment $appointment): JsonResponse
    {
        return $this->move(
            $appointment,
            fn () => $this->booking->start($appointment),
            fn () => 'Consultation started',
        );
    }

    public function complete(Appointment $appointment): JsonResponse
    {
        return $this->move(
            $appointment,
            fn () => $this->booking->complete($appointment),
            fn () => 'Consultation completed',
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
     * refusal is a 422 rather than a 500: "somebody already completed this"
     * is a thing that happens at a busy desk, not a bug.
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
