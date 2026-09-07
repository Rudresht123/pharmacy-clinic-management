<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\Api\V1\Tenant\UpdateDoctorSchedulesRequest;
use App\Http\Resources\Tenant\DoctorScheduleResource;
use App\Models\Tenant\Doctor;
use App\Services\Tenant\DoctorPostings;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * When and where one doctor sits.
 *
 * Nested under the doctor, and written as a whole week rather than a row at
 * a time. That is not a convenience: whether a sitting overlaps depends on
 * every other sitting that doctor has that day, so a per-row endpoint could
 * not validate the one rule that matters.
 *
 * These are availability windows. Nothing here generates or stores
 * appointment slots — those are computed when somebody asks, from the window
 * and its slot length.
 */
class DoctorScheduleController extends BaseApiController
{
    public function index(Doctor $doctor): JsonResponse
    {
        $schedules = $doctor->schedules()
            ->with('location')
            ->orderBy('weekday')
            ->orderBy('starts_at')
            ->get();

        return $this->ok(DoctorScheduleResource::collection($schedules));
    }

    public function update(UpdateDoctorSchedulesRequest $request, Doctor $doctor): JsonResponse
    {
        $submitted = $request->validated('schedules');

        DB::connection($doctor->getConnectionName())->transaction(
            function () use ($doctor, $submitted) {
                /*
                 * Replaced, not merged. The screen submits the week it wants;
                 * anything left out has been taken away, and matching rows up
                 * by id would need ids the editor does not have for the rows
                 * somebody just added.
                 *
                 * Appointments will reference a schedule as provenance and
                 * null on its deletion, so an edited week never orphans a
                 * booking — it only forgets which sitting produced it.
                 */
                $doctor->schedules()->delete();

                foreach ($submitted as $row) {
                    /*
                     * A sitting says the doctor works there.
                     *
                     * Giving somebody Monday at Gurgaon and then asking a
                     * person to also remember to post them to Gurgaon is how a
                     * timetable ends up at a branch the doctor is not assigned
                     * to — and the branch list, which reads postings, would
                     * then not show a doctor who visibly sits there.
                     */
                    app(DoctorPostings::class)->ensure($doctor, (int) $row['location_id']);

                    $doctor->schedules()->create([
                        'location_id' => $row['location_id'],
                        'name' => $row['name'] ?? null,
                        'weekday' => $row['weekday'],
                        'starts_at' => $row['starts_at'],
                        'ends_at' => $row['ends_at'],
                        'slot_minutes' => $row['slot_minutes'],
                        'max_walkins' => $row['max_walkins'] ?? null,
                        'is_active' => $row['is_active'] ?? true,
                    ]);
                }
            }
        );

        return $this->ok(
            DoctorScheduleResource::collection(
                $doctor->schedules()->with('location')->orderBy('weekday')->orderBy('starts_at')->get()
            ),
            'Timings updated',
        );
    }
}
