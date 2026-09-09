<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Tenant\Doctor;
use App\Services\Opd\AvailabilityService;
use App\Services\Opd\DoctorRecords;
use App\Services\Opd\DoctorDay;
use App\Services\Opd\OpdBoard;
use App\Services\Tenancy\TenantBranchAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * The OPD department as a whole, rather than one doctor's list.
 *
 * Two endpoints, both branch-wide: where this person may work, and what is
 * happening there today. Both sit behind `appointments.view` — the same
 * question the queue asks — so somebody who runs the desk can use them
 * without also holding `branches.view`, which they have no reason to.
 */
class OpdController extends BaseApiController
{
    public function __construct(
        private readonly OpdBoard $board,
        private readonly DoctorDay $myDay,
        private readonly AvailabilityService $availability,
        private readonly DoctorRecords $records,
        private readonly TenantBranchAccess $branches,
    ) {}

    /**
     * One doctor's own day, for the doctor.
     *
     * Scoped to whoever is signed in rather than to a doctor id in the
     * request: this is "my list", and an endpoint that took an id would be one
     * doctor reading another's patients under a capability meant to let them
     * read their own.
     */
    /**
     * One doctor's month, for their own calendar.
     *
     * Scoped to whoever is signed in, like their day — this is "my schedule",
     * and an endpoint that took a doctor id would be one doctor reading
     * another's rota under a capability meant for their own.
     */
    public function myMonth(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'location_id' => ['nullable', 'integer'],
        ]);

        $user = $request->user();

        $doctor = $user?->userable_type === Doctor::class
            ? Doctor::on('organization')->find($user->userable_id)
            : null;

        if (! $doctor) {
            abort(403, "This is a doctor's own schedule, and you are not signed in as one.");
        }

        $locationId = $request->filled('location_id')
            ? (int) $request->input('location_id')
            : null;

        if (! $this->branches->currentCanUse($locationId)) {
            abort(403, 'You can only work with the branch you are at.');
        }

        return $this->ok(
            $this->availability->monthFor(
                $doctor,
                $request->filled('from') ? Carbon::parse($request->input('from')) : now(),
                $locationId,
            )
        );
    }

    /**
     * A doctor's own records — one endpoint, four readings.
     *
     * The four screens ask the same question of the same rows from different
     * angles, and splitting them into four routes would be four places to keep
     * the doctor check and the date window in step.
     */
    public function myRecords(Request $request): JsonResponse
    {
        $request->validate([
            'view' => ['required', 'in:appointments,consultations,prescriptions,investigations,follow-ups'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'status' => ['nullable', 'string'],
        ]);

        $doctor = $this->signedInDoctor($request);

        $from = $request->filled('from') ? Carbon::parse($request->input('from')) : null;
        $to = $request->filled('to') ? Carbon::parse($request->input('to')) : null;

        return $this->ok(match ($request->string('view')->toString()) {
            'appointments' => $this->records->appointments(
                $doctor,
                $from,
                $to,
                $request->string('status')->toString() ?: null,
            ),
            'consultations' => $this->records->consultations($doctor, $from, $to),
            'prescriptions' => $this->records->lines($doctor, 'prescription', $from, $to),
            'investigations' => $this->records->lines($doctor, 'investigations', $from, $to),
            'follow-ups' => $this->records->followUps($doctor),
        });
    }

    /**
     * Whoever is signed in, as a doctor.
     *
     * These screens are "mine". Taking a doctor id from the request would let
     * one doctor read another's patients under a capability every doctor holds.
     */
    private function signedInDoctor(Request $request): Doctor
    {
        $user = $request->user();

        $doctor = $user?->userable_type === Doctor::class
            ? Doctor::on('organization')->find($user->userable_id)
            : null;

        if (! $doctor) {
            abort(403, "These are a doctor's own records, and you are not signed in as one.");
        }

        return $doctor;
    }

    public function myDay(Request $request): JsonResponse
    {
        $request->validate([
            'location_id' => ['nullable', 'integer'],
            'date' => ['nullable', 'date'],
        ]);

        $user = $request->user();

        /*
         * A doctor login is a user whose `userable` is the doctor. Anybody
         * else asking has no list of their own — the desk has the board, and
         * this is not it.
         */
        $doctor = $user?->userable_type === Doctor::class
            ? Doctor::on('organization')->with('photograph')->find($user->userable_id)
            : null;

        if (! $doctor) {
            abort(403, 'This is a doctor\'s own list, and you are not signed in as one.');
        }

        $locationId = $request->filled('location_id')
            ? (int) $request->input('location_id')
            : null;

        if (! $this->branches->currentCanUse($locationId)) {
            abort(403, 'You can only work with the branch you are at.');
        }

        return $this->ok(
            $this->myDay->for(
                $doctor,
                $request->filled('date') ? Carbon::parse($request->input('date')) : now(),
                $locationId,
            )
        );
    }

    /**
     * The branches this person may run an OPD day at.
     *
     * Its own endpoint rather than reusing /tenant/locations, for two reasons
     * that are really one: that route needs `branches.view`, and it returns
     * every branch in the organization — including the ones this person would
     * be refused at the moment they picked one.
     */
    public function branches(): JsonResponse
    {
        $branches = $this->board->branchesFor(Auth::guard('web')->user());

        return $this->ok(
            $branches->map(fn ($branch) => [
                'id' => $branch->id,
                'name' => $branch->name,
                'code' => $branch->code,
            ])->all()
        );
    }

    /** Counts, who has waited longest, and what each doctor is doing. */
    public function today(Request $request): JsonResponse
    {
        $request->validate([
            'location_id' => ['required', 'integer'],
            'date' => ['nullable', 'date'],
        ]);

        $locationId = (int) $request->input('location_id');

        // A branch id from the client is just a number until somebody checks
        // it — the same guard the queue applies, for the same reason.
        if (! $this->branches->currentCanUse($locationId)) {
            abort(403, 'You can only work with the branch you are at.');
        }

        return $this->ok(
            $this->board->for(
                $locationId,
                $request->filled('date') ? Carbon::parse($request->input('date')) : now(),
            )
        );
    }
}
