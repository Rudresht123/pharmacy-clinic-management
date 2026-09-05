<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Module;
use App\Models\Platform\Organization;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorSchedule;
use App\Models\Tenant\Location;
use App\Models\Tenant\Role;
use App\Models\Tenant\User;
use App\Support\Opd\Weekday;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TenantTestCase;

/**
 * The OPD department, rather than one doctor's list.
 *
 * Three things are worth proving here, and none of them is "a number comes
 * back". The queue must be readable branch-wide, because a department with
 * several doctors cannot be understood one at a time. The branch list must be
 * the branches this person can actually act on, because offering a choice that
 * always fails is worse than offering none. And both must work for somebody
 * who holds `appointments.view` and nothing else — a receptionist has no
 * reason to hold `branches.view`, and the screen used to be unusable without
 * it.
 */
class OpdBoardTest extends TenantTestCase
{
    use RefreshDatabase;

    /** A Monday, so the weekly pattern has something to match. */
    private const MONDAY = '2026-09-07';

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');
    }

    /**
     * A branch with two doctors sitting, and a patient for each.
     *
     * Two doctors on purpose: every defect this suite is about only shows up
     * once the branch holds more than one.
     *
     * @return array{0: Organization, 1: int, 2: array<int, int>, 3: array<int, int>}
     */
    private function clinic(): array
    {
        $organization = $this->provisionOrganization();

        $organization->moduleEntitlements()->create([
            'module_id' => Module::where('key', 'appointments')->value('id'),
            'is_enabled' => true,
        ]);

        [$branchId, $doctors, $patients] = $this->onTenant($organization, function () {
            $branch = Location::on('organization')->create([
                'name' => 'Gurgaon',
                'code' => 'GGN',
                'type' => Location::CLINIC,
                'is_active' => true,
            ]);

            $doctors = [];
            $patients = [];

            foreach (['Dr. Anjali Sharma', 'Dr. Vikram Rao'] as $index => $name) {
                $doctor = Doctor::on('organization')->create([
                    'name' => $name,
                    'is_active' => true,
                ]);

                DoctorSchedule::on('organization')->create([
                    'doctor_id' => $doctor->id,
                    'location_id' => $branch->id,
                    'weekday' => Weekday::MONDAY,
                    'starts_at' => '10:00',
                    'ends_at' => '13:00',
                    'slot_minutes' => 15,
                    'is_active' => true,
                ]);

                $doctors[] = $doctor->id;

                $patients[] = Customer::on('organization')->create([
                    'name' => 'Patient '.($index + 1),
                    'phone' => '98765000'.$index.$index,
                    'is_active' => true,
                ])->id;
            }

            return [$branch->id, $doctors, $patients];
        });

        $this->signInAsOwner($organization);

        return [$organization, $branchId, $doctors, $patients];
    }

    private function walkIn(int $doctorId, int $branchId, int $patientId): array
    {
        return $this->postJson('/api/v1/tenant/appointments', [
            'customer_id' => $patientId,
            'doctor_id' => $doctorId,
            'location_id' => $branchId,
            'appointment_date' => self::MONDAY,
            'type' => Appointment::WALK_IN,
        ])->assertCreated()->json('data');
    }

    /*
    |--------------------------------------------------------------------------
    | The queue, branch-wide
    |--------------------------------------------------------------------------
    */

    /**
     * The whole branch in one list.
     *
     * `doctor_id` used to be required, which made the screen unusable for its
     * main job: somebody at the desk is looking for a patient, and having to
     * guess which of several doctors they belong to before the list appears is
     * the wrong question.
     */
    public function test_the_queue_reads_every_doctor_at_the_branch(): void
    {
        [, $branchId, $doctors, $patients] = $this->clinic();

        $this->walkIn($doctors[0], $branchId, $patients[0]);
        $this->walkIn($doctors[1], $branchId, $patients[1]);

        $response = $this->getJson(
            "/api/v1/tenant/appointments?location_id={$branchId}&date=".self::MONDAY
        )->assertOk();

        $response->assertJsonCount(2, 'data.queue');

        // And the filter is built from the day itself, so a doctor with
        // nobody booked is never offered as an option that returns nothing.
        $response->assertJsonCount(2, 'data.doctors');
    }

    /** Naming a doctor narrows it. It is a filter, not a prerequisite. */
    public function test_a_doctor_narrows_the_queue_without_being_required(): void
    {
        [, $branchId, $doctors, $patients] = $this->clinic();

        $this->walkIn($doctors[0], $branchId, $patients[0]);
        $this->walkIn($doctors[1], $branchId, $patients[1]);

        $this->getJson(
            "/api/v1/tenant/appointments?location_id={$branchId}"
            ."&doctor_id={$doctors[0]}&date=".self::MONDAY
        )
            ->assertOk()
            ->assertJsonCount(1, 'data.queue')
            ->assertJsonPath('data.queue.0.doctor_id', $doctors[0]);
    }

    /**
     * The doctor filter survives being used.
     *
     * The list of doctors comes from the branch's whole day, not from the
     * queue that has already been narrowed. Built from the narrowed list it
     * would hold exactly one doctor the moment somebody filtered — so the
     * control would collapse to the option already chosen, and there would be
     * no way back to the whole branch without editing the URL.
     */
    public function test_filtering_by_doctor_still_offers_every_doctor(): void
    {
        [, $branchId, $doctors, $patients] = $this->clinic();

        $this->walkIn($doctors[0], $branchId, $patients[0]);
        $this->walkIn($doctors[1], $branchId, $patients[1]);

        $this->getJson(
            "/api/v1/tenant/appointments?location_id={$branchId}"
            ."&doctor_id={$doctors[0]}&date=".self::MONDAY
        )
            ->assertOk()
            // Narrowed…
            ->assertJsonCount(1, 'data.queue')
            // …but still offering the way out of the narrowing.
            ->assertJsonCount(2, 'data.doctors');
    }

    /**
     * The counts the desk glances at, including the one that was missing.
     *
     * `with_doctor` did not exist before, so the strip above the queue could
     * say how many were waiting and how many were seen but not how many were
     * actually in a room.
     */
    public function test_the_queue_counts_who_is_with_a_doctor(): void
    {
        [, $branchId, $doctors, $patients] = $this->clinic();

        $first = $this->walkIn($doctors[0], $branchId, $patients[0]);
        $this->walkIn($doctors[1], $branchId, $patients[1]);

        $this->postJson("/api/v1/tenant/appointments/{$first['id']}/start")->assertOk();

        $this->getJson(
            "/api/v1/tenant/appointments?location_id={$branchId}&date=".self::MONDAY
        )
            ->assertOk()
            ->assertJsonPath('data.waiting', 1)
            ->assertJsonPath('data.with_doctor', 1)
            ->assertJsonPath('data.seen', 0);
    }

    /*
    |--------------------------------------------------------------------------
    | The board
    |--------------------------------------------------------------------------
    */

    /** Counts, who has waited longest, and what each doctor is on. */
    public function test_the_board_reports_the_department(): void
    {
        [, $branchId, $doctors, $patients] = $this->clinic();

        $first = $this->walkIn($doctors[0], $branchId, $patients[0]);
        $this->walkIn($doctors[1], $branchId, $patients[1]);

        $this->postJson("/api/v1/tenant/appointments/{$first['id']}/start")->assertOk();

        $board = $this->getJson(
            "/api/v1/tenant/opd/today?location_id={$branchId}&date=".self::MONDAY
        )->assertOk()->json('data');

        $this->assertSame(1, $board['counts']['waiting']['value']);
        $this->assertSame(1, $board['counts']['in_consultation']['value']);

        // How many rooms are in use, which is not the same number as how many
        // people are in them the moment one doctor sees two in a row.
        $this->assertSame(1, $board['counts']['in_consultation']['doctors']);

        // Both doctors appear, and the one holding a patient says so.
        $this->assertCount(2, $board['doctors']);

        $busy = collect($board['doctors'])->firstWhere('id', $doctors[0]);
        $this->assertSame('with_patient', $busy['state']);

        // Only the one still waiting is on the "waiting longest" list — the
        // patient in the room is not waiting for anything.
        $this->assertCount(1, $board['waiting_longest']);

        // The thresholds come from the server so the board, the queue and any
        // future alert cannot disagree about what "too long" means.
        $this->assertSame(10, $board['thresholds']['warn']);
        $this->assertSame(20, $board['thresholds']['critical']);
    }

    /**
     * The dashboard reads off one request, and every panel is in it.
     *
     * One endpoint rather than eight on purpose: the screen is read at a
     * glance, and separate requests would let its halves describe different
     * moments — counts from one and a queue from another is exactly the bug a
     * shared screen makes hardest to notice. That only holds if the panels
     * really do all arrive together, which is what this asserts.
     */
    public function test_the_dashboard_arrives_in_one_piece(): void
    {
        [, $branchId, $doctors, $patients] = $this->clinic();

        $this->walkIn($doctors[0], $branchId, $patients[0]);

        $this->postJson('/api/v1/tenant/appointments', [
            'customer_id' => $patients[1],
            'doctor_id' => $doctors[1],
            'location_id' => $branchId,
            'appointment_date' => self::MONDAY,
            'type' => Appointment::BOOKED,
            'slot_at' => '11:30',
        ])->assertCreated();

        $this->getJson(
            "/api/v1/tenant/opd/today?location_id={$branchId}&date=".self::MONDAY
        )
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'date', 'location_id', 'branch_name', 'updated_at',
                    'counts' => [
                        'total' => ['value', 'delta', 'delta_pct'],
                        'waiting' => ['value', 'average_wait', 'longest_wait'],
                        'in_consultation' => ['value', 'doctors'],
                        'completed' => ['value', 'delta', 'average_minutes', 'of_total'],
                        'no_show' => ['value', 'of_total'],
                        'expected' => ['value', 'overdue'],
                        'cancelled' => ['value'],
                    ],
                    'tabs' => ['all', 'checked_in', 'in_consultation', 'completed', 'booked'],
                    'queue' => [['id', 'token_no', 'customer_name', 'customer_code', 'age', 'gender', 'doctor_name', 'status', 'next_states']],
                    'flow',
                    'departments',
                    'waiting_longest',
                    'doctors',
                    'upcoming' => [['id', 'slot_at', 'customer_name', 'doctor_name', 'overdue']],
                    'activity',
                    'thresholds' => ['warn', 'critical'],
                ],
            ]);
    }

    /**
     * A wait is whole minutes, not a float.
     *
     * Carbon 3 returns a float from diffInMinutes where Carbon 2 returned an
     * int, so this reached the queue as "100.86940301666667m" and ran straight
     * through the column beside it. Asserting the type rather than the value:
     * the number depends on the clock, but it is an integer whatever it is.
     */
    public function test_a_wait_is_reported_in_whole_minutes(): void
    {
        [$organization, $branchId, $doctors, $patients] = $this->clinic();

        $this->onTenant($organization, function () use ($branchId, $doctors, $patients) {
            Appointment::on('organization')->create([
                'customer_id' => $patients[0],
                'doctor_id' => $doctors[0],
                'location_id' => $branchId,
                'appointment_date' => self::MONDAY,
                'type' => Appointment::WALK_IN,
                'status' => Appointment::STATUS_CHECKED_IN,
                'token_no' => 1,

                // Deliberately not a whole number of minutes ago.
                'checked_in_at' => now()->subMinutes(20)->subSeconds(37),
            ]);
        });

        $waited = $this->getJson(
            "/api/v1/tenant/appointments?location_id={$branchId}&date=".self::MONDAY
        )->assertOk()->json('data.queue.0.waiting_minutes');

        $this->assertIsInt($waited);
    }

    /**
     * The queue opens on the people still in it.
     *
     * Ordered by arrival alone, a morning's completed consultations sat above
     * the handful actually waiting — by noon the screen opened on twenty-six
     * rows nobody could act on and the six that mattered were below the fold.
     *
     * This is NOT the auto-promotion the queue deliberately avoids. That rule
     * is about not floating a booked patient above a walk-in who arrived
     * first, and it is asserted separately; inside the live group nothing
     * reorders anything. What moves is finished work, which is not in the
     * queue in any sense a person at the desk would recognise.
     */
    public function test_the_queue_puts_people_still_waiting_above_finished_ones(): void
    {
        [$organization, $branchId, $doctors, $patients] = $this->clinic();

        $this->onTenant($organization, function () use ($branchId, $doctors, $patients) {
            // Seen an hour ago, and holding the first token of the day.
            Appointment::on('organization')->create([
                'customer_id' => $patients[0],
                'doctor_id' => $doctors[0],
                'location_id' => $branchId,
                'appointment_date' => self::MONDAY,
                'type' => Appointment::WALK_IN,
                'status' => Appointment::STATUS_COMPLETED,
                'token_no' => 1,
                'checked_in_at' => now()->subHours(2),
                'started_at' => now()->subHours(2)->addMinutes(5),
                'completed_at' => now()->subHour(),
            ]);

            // Still waiting, and holding a later one.
            Appointment::on('organization')->create([
                'customer_id' => $patients[1],
                'doctor_id' => $doctors[0],
                'location_id' => $branchId,
                'appointment_date' => self::MONDAY,
                'type' => Appointment::WALK_IN,
                'status' => Appointment::STATUS_CHECKED_IN,
                'token_no' => 2,
                'checked_in_at' => now()->subMinutes(20),
            ]);
        });

        $queue = $this->getJson(
            "/api/v1/tenant/appointments?location_id={$branchId}&date=".self::MONDAY
        )->assertOk()->json('data.queue');

        // Token 2 above token 1, because token 1 has gone home.
        $this->assertSame(Appointment::STATUS_CHECKED_IN, $queue[0]['status']);
        $this->assertSame(2, $queue[0]['token_no']);
        $this->assertSame(Appointment::STATUS_COMPLETED, $queue[1]['status']);
    }

    /**
     * Every tab has rows behind it, not just the busiest one.
     *
     * The dashboard's queue is capped, and capping the day as a whole — sorted
     * live-first — meant a busy morning filled the entire allowance with people
     * still waiting. The Completed tab then read 24 and showed nothing, because
     * not one completed row had been sent. Capping per status is what fixes it,
     * and this is the shape that proves it: more waiting patients than the cap,
     * plus one of everything else.
     */
    public function test_the_dashboard_queue_carries_rows_for_every_tab(): void
    {
        [$organization, $branchId, $doctors, $patients] = $this->clinic();

        $this->onTenant($organization, function () use ($branchId, $doctors, $patients) {
            // Twelve waiting — comfortably more than the eight-row cap.
            foreach (range(1, 12) as $n) {
                Appointment::on('organization')->create([
                    'customer_id' => $patients[$n % count($patients)],
                    'doctor_id' => $doctors[0],
                    'location_id' => $branchId,
                    'appointment_date' => self::MONDAY,
                    'type' => Appointment::WALK_IN,
                    'status' => Appointment::STATUS_CHECKED_IN,
                    'token_no' => $n,
                    'checked_in_at' => now()->subMinutes(30 - $n),
                ]);
            }

            // And one of each of the others, which the old cap buried.
            foreach ([
                Appointment::STATUS_COMPLETED,
                Appointment::STATUS_IN_CONSULTATION,
                Appointment::STATUS_BOOKED,
            ] as $index => $status) {
                Appointment::on('organization')->create([
                    'customer_id' => $patients[$index % count($patients)],
                    'doctor_id' => $doctors[1],
                    'location_id' => $branchId,
                    'appointment_date' => self::MONDAY,
                    'type' => Appointment::BOOKED,
                    'status' => $status,
                    'slot_at' => sprintf('1%d:00', $index),
                    'token_no' => $status === Appointment::STATUS_BOOKED ? null : 90 + $index,
                    'checked_in_at' => $status === Appointment::STATUS_BOOKED
                        ? null
                        : now()->subHour(),
                    'started_at' => $status === Appointment::STATUS_BOOKED
                        ? null
                        : now()->subMinutes(40),
                    'completed_at' => $status === Appointment::STATUS_COMPLETED
                        ? now()->subMinutes(20)
                        : null,
                ]);
            }
        });

        $board = $this->getJson(
            "/api/v1/tenant/opd/today?location_id={$branchId}&date=".self::MONDAY
        )->assertOk()->json('data');

        $byStatus = collect($board['queue'])->groupBy('status');

        foreach ([
            Appointment::STATUS_CHECKED_IN,
            Appointment::STATUS_COMPLETED,
            Appointment::STATUS_IN_CONSULTATION,
            Appointment::STATUS_BOOKED,
        ] as $status) {
            $this->assertTrue(
                $byStatus->has($status),
                "The dashboard sent no {$status} rows, so its tab would count them and show none.",
            );
        }

        // The waiting list is capped rather than sent whole…
        $this->assertCount(8, $byStatus[Appointment::STATUS_CHECKED_IN]);

        // …while the tab keeps the day's real total, which is what the screen
        // reads out as "showing 8 of 12".
        $this->assertSame(12, $board['tabs']['checked_in']);
    }

    /**
     * Today's list split by what the doctors do.
     *
     * There is no departments table, and specialisation answers the same
     * question without inventing a second place for the truth to live. The
     * shares are percentages of the day, so they have to add up.
     */
    public function test_the_day_is_split_by_speciality(): void
    {
        [$organization, $branchId, $doctors, $patients] = $this->clinic();

        $this->onTenant($organization, function () use ($doctors) {
            Doctor::on('organization')->whereKey($doctors[0])
                ->update(['specialisation' => 'Cardiology']);
            Doctor::on('organization')->whereKey($doctors[1])
                ->update(['specialisation' => 'Paediatrics']);
        });

        $this->walkIn($doctors[0], $branchId, $patients[0]);
        $this->walkIn($doctors[1], $branchId, $patients[1]);

        $split = $this->getJson(
            "/api/v1/tenant/opd/today?location_id={$branchId}&date=".self::MONDAY
        )->assertOk()->json('data.departments');

        $this->assertCount(2, $split);
        $this->assertSame(100, collect($split)->sum('share'));
        $this->assertEqualsCanonicalizing(
            ['Cardiology', 'Paediatrics'],
            collect($split)->pluck('label')->all(),
        );
    }

    /**
     * Today's figure beside yesterday's, and no percentage out of nothing.
     *
     * A clinic that saw nobody yesterday and two people today has not improved
     * by two hundred per cent, it has opened — so the share is withheld rather
     * than invented, and the screen shows the plain difference instead.
     */
    public function test_a_comparison_against_an_empty_yesterday_has_no_percentage(): void
    {
        [, $branchId, $doctors, $patients] = $this->clinic();

        $this->walkIn($doctors[0], $branchId, $patients[0]);

        $total = $this->getJson(
            "/api/v1/tenant/opd/today?location_id={$branchId}&date=".self::MONDAY
        )->assertOk()->json('data.counts.total');

        $this->assertSame(1, $total['value']);
        $this->assertSame(1, $total['delta']);
        $this->assertNull($total['delta_pct']);
    }

    /**
     * How many came through the door each hour, split new against returning.
     *
     * Reconstructed from the timestamps rather than recorded as it happens, so
     * the test writes a morning by hand: three people arriving in one hour, of
     * whom two have since been called in. Being called in must not remove
     * somebody from the hour they arrived in — that was the bug an earlier
     * queue-depth version of this panel could not have caught.
     */
    public function test_the_flow_counts_arrivals_in_the_hour_they_came(): void
    {
        /*
         * Pinned to a mid-morning clock.
         *
         * The chart is built from "two hours ago" against "the hour it is
         * now", so run late enough in the evening the two straddle midnight,
         * the data is written for one date and read back for the next, and the
         * test fails for a reason that has nothing to do with the code.
         */
        $this->travelTo(Carbon::parse(self::MONDAY.' 11:30:00'));

        [$organization, $branchId, $doctors, $patients] = $this->clinic();

        $this->onTenant($organization, function () use ($branchId, $doctors, $patients) {
            foreach ([
                // [checked in, started] — null start means still waiting.
                [now()->subHours(2), now()->subHours(2)->addMinutes(50)],
                [now()->subHours(2)->addMinutes(5), now()->subMinutes(20)],
                [now()->subHours(2)->addMinutes(10), null],
            ] as $index => [$arrived, $started]) {
                Appointment::on('organization')->create([
                    'customer_id' => $patients[$index % count($patients)],
                    'doctor_id' => $doctors[0],
                    'location_id' => $branchId,
                    'appointment_date' => now()->toDateString(),
                    'type' => Appointment::WALK_IN,
                    'status' => $started
                        ? Appointment::STATUS_COMPLETED
                        : Appointment::STATUS_CHECKED_IN,
                    'token_no' => $index + 1,
                    'checked_in_at' => $arrived,
                    'started_at' => $started,
                    'completed_at' => $started?->copy()->addMinutes(10),
                ]);
            }
        });

        $flow = $this->getJson(
            "/api/v1/tenant/opd/today?location_id={$branchId}&date=".now()->toDateString()
        )->assertOk()->json('data.flow');

        $this->assertNotEmpty($flow, 'The flow was not reconstructed.');

        /*
         * All three arrived within five minutes of each other, so they belong
         * to one column — including the two already seen. An hour's flow is
         * who came through the door, not who is still standing there.
         */
        $this->assertSame(3, $flow[0]['value']);

        // And each column splits into the two the legend names, adding back up
        // to the column's own height.
        $this->assertSame(
            $flow[0]['value'],
            $flow[0]['fresh'] + $flow[0]['returning'],
        );

        // The axis runs to the present hour even though nobody has arrived in
        // it: a quiet hour is information, and an axis that closes the gap is
        // a different chart.
        $this->assertSame(0, $flow[count($flow) - 1]['value']);
    }

    /** No arrivals is an empty series, not a row of zeroes. */
    public function test_the_flow_is_empty_before_anybody_arrives(): void
    {
        [, $branchId, $doctors, $patients] = $this->clinic();

        // Booked, never arrived: nothing to plot.
        $this->postJson('/api/v1/tenant/appointments', [
            'customer_id' => $patients[0],
            'doctor_id' => $doctors[0],
            'location_id' => $branchId,
            'appointment_date' => self::MONDAY,
            'type' => Appointment::BOOKED,
            'slot_at' => '10:30',
        ])->assertCreated();

        $this->getJson(
            "/api/v1/tenant/opd/today?location_id={$branchId}&date=".self::MONDAY
        )
            ->assertOk()
            ->assertJsonCount(0, 'data.flow');
    }

    /** Cancellations and no-shows are counted, but kept out of the flow. */
    public function test_no_shows_and_cancellations_are_reported_separately(): void
    {
        [, $branchId, $doctors, $patients] = $this->clinic();

        $first = $this->walkIn($doctors[0], $branchId, $patients[0]);

        $second = $this->postJson('/api/v1/tenant/appointments', [
            'customer_id' => $patients[1],
            'doctor_id' => $doctors[1],
            'location_id' => $branchId,
            'appointment_date' => self::MONDAY,
            'type' => Appointment::BOOKED,
            'slot_at' => '10:30',
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/tenant/appointments/{$second['id']}/no-show")->assertOk();
        $this->postJson("/api/v1/tenant/appointments/{$first['id']}/cancel", [
            'reason' => 'Went elsewhere',
        ])->assertOk();

        $board = $this->getJson(
            "/api/v1/tenant/opd/today?location_id={$branchId}&date=".self::MONDAY
        )->assertOk()->json('data');

        $this->assertSame(1, $board['counts']['no_show']['value']);
        $this->assertSame(1, $board['counts']['cancelled']['value']);

        /*
         * And a cancelled appointment is not counted in "of_total". The Seen
         * tile reads "22 of 39 on the list", and a list that includes people
         * who were called off can never be completed — the denominator would
         * be a target nobody could reach.
         */
        $this->assertSame(1, $board['counts']['completed']['of_total']);
    }

    /*
    |--------------------------------------------------------------------------
    | Who may see what
    |--------------------------------------------------------------------------
    */

    /**
     * A receptionist holds `appointments.view` and nothing else.
     *
     * The branch control used to be fed by /tenant/locations, which
     * `branches.view` guards. Somebody without it got an empty dropdown, no
     * branch was ever selected, the queue never loaded and the booking button
     * stayed disabled — with nothing on screen explaining why.
     */
    public function test_a_receptionist_without_branches_view_still_gets_their_branches(): void
    {
        [$organization, $branchId] = $this->clinic();

        $this->setStaffCapabilities($organization, ['appointments.view']);
        $this->placeStaffAt($organization, $branchId);

        $this->signInAsStaff($organization);

        // Proving the premise: the old source of the list is refused.
        $this->getJson('/api/v1/tenant/locations')->assertStatus(403);

        $this->getJson('/api/v1/tenant/opd/branches')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $branchId);
    }

    /**
     * Only the branches they may act on.
     *
     * The queue refuses a branch this person is not a member of, so offering
     * one in a picker is a guaranteed 403 — the two halves of one mistake.
     */
    public function test_the_branch_list_omits_branches_this_person_cannot_use(): void
    {
        [$organization, $branchId] = $this->clinic();

        $elsewhere = $this->onTenant($organization, fn () => Location::on('organization')->create([
            'name' => 'Noida',
            'code' => 'NOI',
            'type' => Location::CLINIC,
            'is_active' => true,
        ])->id);

        $this->setStaffCapabilities($organization, ['appointments.view']);
        $this->placeStaffAt($organization, $branchId);

        $this->signInAsStaff($organization);

        $ids = collect($this->getJson('/api/v1/tenant/opd/branches')->assertOk()->json('data'))
            ->pluck('id')
            ->all();

        $this->assertContains($branchId, $ids);
        $this->assertNotContains($elsewhere, $ids);

        // And the branch that was withheld really would have been refused,
        // which is what makes withholding it the right call rather than a
        // cosmetic one.
        $this->getJson("/api/v1/tenant/opd/today?location_id={$elsewhere}&date=".self::MONDAY)
            ->assertStatus(403);
    }

    /** The owner runs the whole network, so every active branch is theirs. */
    public function test_the_owner_gets_every_branch(): void
    {
        [$organization, $branchId] = $this->clinic();

        $this->onTenant($organization, fn () => Location::on('organization')->create([
            'name' => 'Noida',
            'code' => 'NOI',
            'type' => Location::CLINIC,
            'is_active' => true,
        ]));

        $this->signInAsOwner($organization);

        $this->getJson('/api/v1/tenant/opd/branches')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertNotNull($branchId);
    }

    /**
     * A doctor's own login says which doctor it is.
     *
     * The link has existed since doctors were built and nothing read it, so a
     * doctor signing in got the whole department's queue and had to find
     * themselves in a filter. Null for everybody else — a doctor may have no
     * login at all, which is why doctors are their own table.
     */
    public function test_a_doctors_login_reports_which_doctor_it_is(): void
    {
        [$organization, , $doctors] = $this->clinic();

        $this->onTenant($organization, function () use ($doctors) {
            User::on('organization')->create([
                'name' => 'Dr. Anjali Sharma',
                'email' => 'anjali@clinic.test',
                'password' => bcrypt(self::PASSWORD),
                'is_active' => true,
                'role' => User::STAFF,
                'role_id' => Role::on('organization')->where('slug', Role::SEEDED_STAFF)->value('id'),
                'userable_type' => Doctor::class,
                'userable_id' => $doctors[0],
            ]);
        });

        $this->signIn($organization, 'anjali@clinic.test');

        $this->getJson('/api/v1/tenant/auth/me')
            ->assertOk()
            ->assertJsonPath('data.doctor_id', $doctors[0]);
    }

    /** And an ordinary desk account says it belongs to no doctor. */
    public function test_a_desk_login_belongs_to_no_doctor(): void
    {
        [$organization] = $this->clinic();

        $this->signInAsStaff($organization);

        $this->getJson('/api/v1/tenant/auth/me')
            ->assertOk()
            ->assertJsonPath('data.doctor_id', null);
    }

    /** Both endpoints are behind the module, like everything else in OPD. */
    public function test_the_board_is_behind_the_module(): void
    {
        $organization = $this->provisionOrganization();
        $this->signInAsOwner($organization);

        $this->getJson('/api/v1/tenant/opd/branches')->assertStatus(403);
        $this->getJson('/api/v1/tenant/opd/today?location_id=1')->assertStatus(403);
    }

    /** And behind the capability, for somebody who holds none of OPD. */
    public function test_the_board_needs_appointments_view(): void
    {
        [$organization] = $this->clinic();

        $this->setStaffCapabilities($organization, ['customers.view']);
        $this->signInAsStaff($organization);

        $this->getJson('/api/v1/tenant/opd/branches')->assertStatus(403);
    }

    /**
     * Somebody attached to no branch gets an empty list, not an error.
     *
     * Head office, typically. The screen says "you are not attached to a
     * branch" and explains what to do, which is more use than an empty board
     * or a 500.
     */
    public function test_head_office_gets_no_branches_rather_than_a_failure(): void
    {
        [$organization] = $this->clinic();

        $this->setStaffCapabilities($organization, ['appointments.view']);

        // Deliberately not placed at a branch: they hold no membership.
        $this->onTenant($organization, function () {
            $role = Role::on('organization')->where('slug', Role::SEEDED_STAFF)->first();

            $this->assertNotNull($role);
        });

        $this->signInAsStaff($organization);

        $this->getJson('/api/v1/tenant/opd/branches')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
