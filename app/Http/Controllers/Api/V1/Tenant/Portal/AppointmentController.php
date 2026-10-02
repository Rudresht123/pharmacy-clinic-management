<?php

namespace App\Http\Controllers\Api\V1\Tenant\Portal;

use App\Http\Requests\Api\V1\Tenant\Portal\BookAppointmentRequest;
use App\Http\Resources\Tenant\Portal\PortalAppointmentResource;
use App\Services\Portal\PatientAppointments;
use App\Services\Portal\PatientNotifications;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/** The patient's own appointments. */
class AppointmentController extends PortalController
{
    public function __construct(
        private readonly PatientAppointments $appointments,
        private readonly PatientNotifications $notifications,
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
}
