<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\Api\V1\Tenant\StoreScheduleExceptionRequest;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorScheduleException;
use App\Services\Opd\AvailabilityService;
use App\Services\Tenancy\TenantBranchAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Who is available, where, on a given day.
 *
 * Every answer here is derived — the weekly sittings for that weekday, minus
 * what is cancelled, with changed hours applied, plus anything extra. There
 * is no table of slots and there will not be one: a schedule is an
 * availability window, and materialising it would mean regenerating on every
 * edit and reconciling bookings against rows that had moved underneath them.
 */
class AvailabilityController extends BaseApiController
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly TenantBranchAccess $branches,
    ) {}

    /**
     * A branch's day: every doctor sitting there, and their slots.
     *
     * The branch is checked against the caller rather than taken on trust —
     * a location id in a request is just a number until somebody asks
     * whether the person sending it works there.
     */
    public function day(Request $request): JsonResponse
    {
        $request->validate([
            'date' => ['required', 'date'],
            'location_id' => ['required', 'integer'],
        ]);

        $locationId = (int) $request->input('location_id');

        if (! $this->branches->currentCanUse($locationId)) {
            abort(403, 'You can only see the day at the branch you work at.');
        }

        return $this->ok([
            'date' => $request->date('date')->toDateString(),
            'location_id' => $locationId,
            'doctors' => $this->availability->dayAtLocation(
                Carbon::parse($request->input('date')),
                $locationId,
            ),
        ]);
    }

    /** One doctor's day, with the slots each sitting divides into. */
    public function forDoctor(Request $request, Doctor $doctor): JsonResponse
    {
        $request->validate([
            'date' => ['required', 'date'],
            'location_id' => ['nullable', 'integer'],
        ]);

        $locationId = $request->filled('location_id')
            ? (int) $request->input('location_id')
            : null;

        if (! $this->branches->currentCanUse($locationId)) {
            abort(403, 'You can only see the day at the branch you work at.');
        }

        $sessions = $this->availability->sessionsFor(
            $doctor,
            Carbon::parse($request->input('date')),
            $locationId,
        );

        return $this->ok([
            'doctor_id' => $doctor->id,
            'doctor_name' => $doctor->name,
            'date' => $request->date('date')->toDateString(),
            'sessions' => array_map(
                fn (array $session) => [
                    ...$session,
                    'slots' => $this->availability->slotsFor($session),
                ],
                $sessions,
            ),
        ]);
    }

    /**
     * Leave, holidays, moved hours and extra clinics — the departures from
     * the weekly pattern.
     */
    public function exceptions(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'doctor_id' => ['nullable', 'integer'],
        ]);

        $rows = DoctorScheduleException::on('organization')
            ->with(['doctor', 'location', 'schedule'])
            // Default window: today onwards, because the question is almost
            // always "what is coming", not "what has been".
            ->whereDate('date', '>=', $request->input('from', Carbon::today()->toDateString()))
            ->when(
                $request->filled('to'),
                fn ($query) => $query->whereDate('date', '<=', $request->input('to'))
            )
            ->when(
                $request->filled('doctor_id'),
                fn ($query) => $query->where('doctor_id', $request->integer('doctor_id'))
            )
            ->orderBy('date')
            ->get();

        return $this->ok($rows->map(fn (DoctorScheduleException $row) => [
            'id' => $row->id,
            'doctor_id' => $row->doctor_id,
            'doctor_name' => $row->doctor?->name,
            'doctor_schedule_id' => $row->doctor_schedule_id,
            'location_id' => $row->location_id,
            'location_name' => $row->location?->name,
            'date' => $row->date?->toDateString(),
            'type' => $row->type,
            'starts_at' => $row->starts_at ? substr($row->starts_at, 0, 5) : null,
            'ends_at' => $row->ends_at ? substr($row->ends_at, 0, 5) : null,
            'slot_minutes' => $row->slot_minutes,
            'max_walkins' => $row->max_walkins,
            'reason' => $row->reason,
            'whole_day' => $row->isWholeDayOff(),
        ]));
    }

    public function storeException(StoreScheduleExceptionRequest $request): JsonResponse
    {
        $exception = DoctorScheduleException::on('organization')->create($request->validated());

        return $this->created(['id' => $exception->id], 'Saved');
    }

    public function destroyException(DoctorScheduleException $exception): JsonResponse
    {
        $exception->delete();

        return $this->ok(null, 'Removed');
    }
}
