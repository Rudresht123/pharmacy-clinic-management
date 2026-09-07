<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which branches a doctor works at — said once, before any timetable.
 *
 * Until now this was implied by `doctor_schedules`: a doctor "belonged to"
 * Gurgaon because they had a Monday sitting there. That reads fine until you
 * try to state the simpler fact first. A practice takes on a consultant who
 * will cover two branches; nobody has agreed his hours yet; there is nowhere
 * to record that he works at either. And a doctor added a moment ago has no
 * sittings at all, so every branch-filtered list answered "not here" when the
 * truth was "not scheduled anywhere yet".
 *
 * So the assignment becomes its own row. `doctor_schedules` keeps doing what
 * it always did — WHEN somebody sits — and this says WHERE they work at all.
 * The two are different questions and were only ever answered by one table
 * because the second question had not been asked yet.
 *
 * Still no `organisation_id`, here or anywhere in a tenant database: the
 * organisation is the database. A doctor and a branch in the same file are in
 * the same organisation by construction, and a column asserting it could only
 * ever be redundant or wrong.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_locations', function (Blueprint $table) {
            $table->id();

            /*
             * Cascade on the doctor, restrict on the branch.
             *
             * Deleting a doctor takes their postings with them — the row means
             * nothing without them. Deleting a branch is refused while anybody
             * is posted to it, the same rule `doctor_schedules` already
             * applies, so a branch cannot vanish out from under a timetable.
             */
            $table->foreignId('doctor_id')->constrained('doctors')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();

            /*
             * A posting that has been ended rather than removed.
             *
             * A doctor who has stopped covering a branch is not the same fact
             * as one who was never there: the branch's old appointments still
             * name them, and somebody looking at last month's queue should not
             * find a doctor the records insist never worked there.
             */
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // One posting per doctor per branch. Assigning somebody twice is
            // the duplicate this table exists to make impossible.
            $table->unique(['doctor_id', 'location_id']);

            // "Who works here" — the branch's own doctor list.
            $table->index(['location_id', 'is_active']);
        });

        /*
         * Everything the schedules already implied.
         *
         * Every doctor with a sitting at a branch is posted to it, so nothing
         * that worked yesterday stops working today: the same doctors appear
         * on the same branch lists, and the same schedules keep their meaning.
         * Written in SQL rather than looped, because this runs against every
         * tenant database on deploy.
         */
        DB::statement(<<<'SQL'
            INSERT INTO doctor_locations (doctor_id, location_id, is_active, created_at, updated_at)
            SELECT DISTINCT doctor_id, location_id, TRUE, NOW(), NOW()
            FROM doctor_schedules
            ON CONFLICT (doctor_id, location_id) DO NOTHING
        SQL);

        /*
         * And anyone the schedules could not speak for.
         *
         * A doctor with no sittings anywhere was invisible to every
         * branch-filtered list. There is no way to know which branch they were
         * meant for, so they are posted to all of them — visible everywhere
         * rather than nowhere, which is the error a person can actually see
         * and correct.
         */
        DB::statement(<<<'SQL'
            INSERT INTO doctor_locations (doctor_id, location_id, is_active, created_at, updated_at)
            SELECT d.id, l.id, TRUE, NOW(), NOW()
            FROM doctors d
            CROSS JOIN locations l
            WHERE d.deleted_at IS NULL
              AND l.deleted_at IS NULL
              AND NOT EXISTS (SELECT 1 FROM doctor_schedules s WHERE s.doctor_id = d.id)
            ON CONFLICT (doctor_id, location_id) DO NOTHING
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_locations');
    }
};
