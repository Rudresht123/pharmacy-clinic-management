<?php

namespace App\Http\Controllers\Api\V1\Tenant\Portal;

use App\Http\Resources\Tenant\Portal\PortalDoctorResource;
use App\Models\Tenant\Doctor;
use App\Services\Portal\BookableDoctors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Finding a doctor, and when they are free. */
class DoctorController extends PortalController
{
    public function __construct(
        private readonly BookableDoctors $doctors,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'specialisation' => ['nullable', 'string', 'max:120'],
        ]);

        $organization = $this->organization($request);

        $doctors = $this->doctors
            ->search($request->input('q'), $request->input('specialisation'))
            ->each(fn (Doctor $doctor) => $doctor->setAttribute(
                'next_available',
                $this->doctors->nextAvailable($organization, $doctor),
            ));

        return $this->ok(
            PortalDoctorResource::collection($doctors),
            meta: ['specialisations' => $this->doctors->specialisations()],
        );
    }

    public function show(Request $request, Doctor $doctor): JsonResponse
    {
        $this->assertBookable($doctor);

        $doctor->load(['department', 'photograph', 'schedules' => fn ($query) => $query
            ->where('is_active', true)
            ->with('location:id,name')]);

        $doctor->setAttribute('next_available', $this->doctors->nextAvailable($this->organization($request), $doctor));

        return $this->ok(PortalDoctorResource::make($doctor));
    }

    public function slots(Request $request, Doctor $doctor): JsonResponse
    {
        $this->assertBookable($doctor);

        $request->validate([
            'date' => [
                'required', 'date_format:Y-m-d', 'after_or_equal:today',
                'before_or_equal:'.now()->addDays(BookableDoctors::DAYS_AHEAD)->toDateString(),
            ],
        ]);

        $date = Carbon::parse($request->input('date'));

        return $this->ok([
            'doctor_id' => $doctor->id,
            'date' => $date->toDateString(),
            'sessions' => $this->doctors->openSlots($this->organization($request), $doctor, $date),
        ]);
    }

    /** A doctor who has left, or was never offered, is not there to a patient. */
    private function assertBookable(Doctor $doctor): void
    {
        if (! $doctor->is_active) {
            abort(404, 'That doctor is not taking bookings.');
        }
    }
}
