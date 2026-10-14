<?php

namespace App\Http\Controllers\Api\V1\Tenant\Portal;

use App\Http\Requests\Api\V1\Tenant\Portal\BookAppointmentRequest;
use App\Http\Resources\Tenant\Portal\PortalAppointmentResource;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Location;
use App\Services\Opd\RescheduleRequests;
use App\Services\Permissions\Permission;
use App\Services\Portal\BookableDoctors;
use App\Services\Portal\PatientAppointments;
use App\Services\Portal\PatientNotifications;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * The patient's own appointments.
 *
 * @group Patient Portal > Appointments
 */
class AppointmentController extends PortalController
{
    public function __construct(
        private readonly PatientAppointments $appointments,
        private readonly PatientNotifications $notifications,
        private readonly Permission $permission,
        private readonly RescheduleRequests $reschedules,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['scope' => ['nullable', Rule::in(['upcoming', 'past'])]]);

        $patient = $this->patient($request);

        $rows = $request->input('scope') === 'past'
            ? $this->appointments->past($patient)
            : $this->appointments->upcoming($patient);

        return $this->ok(PortalAppointmentResource::collection($rows));
    }

    public function store(BookAppointmentRequest $request): JsonResponse
    {
        /*
         * A video visit is one the patient has to answer. Booking one without
         * the capability to take the call would leave the doctor ringing a
         * phone that cannot pick up.
         */
        if ($request->input('mode') === Appointment::MODE_VIDEO
            && ! $this->permission->allows($this->organization($request), $request->user(), 'portal.video_calls')) {
            return $this->fail('Video visits are not available to you at this clinic. Please book an in-clinic visit.', 403);
        }

        try {
            $appointment = $this->appointments->book(
                $this->organization($request),
                $this->patient($request),
                $request->validated(),
            );
        } catch (RuntimeException $exception) {
            // The slot went, or they already hold one that day — something
            // the patient can act on by choosing again, not a server fault.
            return $this->fail($exception->getMessage(), 422);
        }

        $this->notifications->appointmentBooked($appointment);

        return $this->created(PortalAppointmentResource::make($appointment), 'Appointment booked');
    }

    /** Call off one of their own bookings, before they have checked in. */
    public function cancel(Request $request, int $appointment): JsonResponse
    {
        try {
            $cancelled = $this->appointments->cancel($this->patient($request), $appointment);
        } catch (ModelNotFoundException) {
            // Caught first: it is itself a RuntimeException, and somebody
            // else's appointment must read as missing, not as refused.
            return $this->fail('That appointment was not found.', 404);
        } catch (RuntimeException $exception) {
            // Already checked in, already past — something to tell them, not a fault.
            return $this->fail($exception->getMessage(), 422);
        }

        $this->notifications->appointmentCancelled($cancelled);

        return $this->ok(PortalAppointmentResource::make($cancelled), 'Appointment cancelled');
    }

    /**
     * Ask to move one of their own bookings. The clinic answers; until then
     * the appointment stays where it is. Asking again replaces the request.
     */
    public function reschedule(Request $request, int $appointment): JsonResponse
    {
        $validated = $request->validate([
            'appointment_date' => [
                'required', 'date_format:Y-m-d', 'after_or_equal:today',
                'before_or_equal:'.now()->addDays(BookableDoctors::DAYS_AHEAD)->toDateString(),
            ],
            'slot_at' => ['required', 'date_format:H:i'],
            'location_id' => ['nullable', 'integer', Rule::exists(Location::class, 'id')->whereNull('deleted_at')],
            'reason' => ['nullable', 'string', 'max:191'],
        ]);

        try {
            $this->reschedules->request($this->organization($request), $this->patient($request), $appointment, $validated);
        } catch (ModelNotFoundException) {
            return $this->fail('That appointment was not found.', 404);
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok($this->fresh($appointment), 'Request sent. The clinic will confirm the new time.');
    }

    /** Take back a request the clinic has not answered yet. */
    public function withdrawReschedule(Request $request, int $appointment): JsonResponse
    {
        try {
            $this->reschedules->withdraw($this->patient($request), $appointment);
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok($this->fresh($appointment), 'Request withdrawn');
    }

    private function fresh(int $appointment): PortalAppointmentResource
    {
        return PortalAppointmentResource::make(
            Appointment::query()
                ->with(['doctor.photograph', 'location', 'liveVideoCall', 'latestRescheduleRequest'])
                ->findOrFail($appointment),
        );
    }
}
