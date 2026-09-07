<?php

namespace App\Services\Tenant;

use App\Models\Tenant\Doctor;
use App\Models\Tenant\Location;
use Illuminate\Support\Facades\DB;

/**
 * Where a doctor works, as distinct from when they sit.
 *
 * The two were one thing until now — a doctor "belonged to" a branch because
 * they had a sitting there — and that only holds while every posting has a
 * timetable behind it. A consultant taken on to cover two branches before
 * anybody agrees his hours had nowhere to be recorded at all.
 *
 * This owns the posting. `doctor_schedules` still owns the timetable, and the
 * one rule between them is that a sitting cannot exist at a branch the doctor
 * is not posted to — enforced here rather than by a constraint, because the
 * repair is "post them there", which a database error cannot say.
 */
class DoctorPostings
{
    /**
     * Replace a doctor's branches with exactly these.
     *
     * The screen sends the set it wants, so a branch left out is one the
     * doctor no longer covers. Replacing rather than merging matches how the
     * week is saved, and means unticking a box does what unticking a box
     * looks like it does.
     *
     * @param  list<int>  $locationIds
     */
    public function assign(Doctor $doctor, array $locationIds): void
    {
        /*
         * Only branches that exist and are this organization's.
         *
         * "This organization's" is free: the branch table being read is the
         * tenant's own database, so an id from anywhere else simply is not
         * found. The check is against typos and stale screens, not against
         * cross-tenant reach, which physical tenancy already makes impossible.
         */
        $valid = Location::query()
            ->whereIn('id', $locationIds)
            ->pluck('id')
            ->all();

        DB::connection($doctor->getConnectionName())->transaction(function () use ($doctor, $valid) {
            $before = $doctor->postings()->pluck('location_id')->all();
            $removed = array_diff($before, $valid);

            $doctor->postings()->sync($valid);

            /*
             * A branch they no longer cover loses its sittings too.
             *
             * Left behind, the timetable would say a doctor sits somewhere the
             * posting says they do not work, and the availability service
             * reads the timetable — so the branch would go on offering slots
             * for somebody who is not theirs.
             *
             * Appointments already made survive: they carry their own date,
             * time and branch, and `doctor_schedule_id` is provenance that
             * nulls on delete. A past booking forgets which sitting produced
             * it and remains a booking.
             */
            if ($removed !== []) {
                $doctor->schedules()->whereIn('location_id', $removed)->delete();
            }
        });
    }

    /**
     * Post a doctor to a branch if they are not already.
     *
     * Called when a sitting is written: giving somebody Monday at Gurgaon says
     * they work at Gurgaon, and making that a second, separate step somebody
     * has to remember is how a timetable ends up at a branch the doctor is not
     * posted to.
     */
    public function ensure(Doctor $doctor, int $locationId): void
    {
        $doctor->postings()->syncWithoutDetaching([$locationId]);
    }
}
