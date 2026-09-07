<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Module;
use App\Models\Platform\Organization;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorSchedule;
use App\Models\Tenant\DoctorScheduleException;
use App\Models\Tenant\Location;
use App\Models\Tenant\User;
use App\Services\Opd\AvailabilityService;
use App\Support\Opd\Weekday;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TenantTestCase;

/**
 * OPD Phase 2a — availability.
 *
 * Availability is derived, never stored: the weekly sittings for that
 * weekday, minus what is cancelled, with changed hours applied, plus
 * anything extra. Each of those four rules is asserted on its own, because
 * getting one wrong is how a patient is booked with a doctor who is on
 * leave.
 */
class AvailabilityTest extends TenantTestCase
{
    use RefreshDatabase;

    /** A Monday, so the weekly pattern has something to match. */
    private const MONDAY = '2026-09-07';

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');
    }

    private function enableOpd(Organization $organization): void
    {
        $organization->moduleEntitlements()->create([
            'module_id' => Module::where('key', 'appointments')->value('id'),
            'is_enabled' => true,
        ]);
    }

    /** @return array{0: Organization, 1: int, 2: int} organization, doctor, branch */
    private function clinic(): array
    {
        $organization = $this->provisionOrganization();
        $this->enableOpd($organization);

        [$doctorId, $branchId] = $this->onTenant($organization, function () {
            $branch = Location::on('organization')->create([
                'name' => 'Gurgaon',
                'code' => 'GGN',
                'type' => Location::CLINIC,
                'is_active' => true,
            ]);

            $doctor = Doctor::on('organization')->create([
                'name' => 'Dr. Anjali Sharma',
                'is_active' => true,
            ]);

            DoctorSchedule::on('organization')->create([
                'doctor_id' => $doctor->id,
                'location_id' => $branch->id,
                'name' => 'Morning OPD',
                // The stored numbering: Monday is 0.
                'weekday' => Weekday::MONDAY,
                'starts_at' => '10:00',
                'ends_at' => '13:00',
                'slot_minutes' => 15,
                'is_active' => true,
            ]);

            return [$doctor->id, $branch->id];
        });

        return [$organization, $doctorId, $branchId];
    }

    private function sessionsOn(Organization $organization, int $doctorId, string $date): array
    {
        return $this->onTenant($organization, fn () => app(AvailabilityService::class)->sessionsFor(
            Doctor::on('organization')->findOrFail($doctorId),
            Carbon::parse($date),
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | The weekly pattern
    |--------------------------------------------------------------------------
    */

    /** A sitting appears on its weekday and on no other. */
    public function test_a_sitting_appears_only_on_its_weekday(): void
    {
        [$organization, $doctorId] = $this->clinic();

        $this->assertCount(1, $this->sessionsOn($organization, $doctorId, self::MONDAY));

        // The Tuesday after.
        $this->assertCount(0, $this->sessionsOn($organization, $doctorId, '2026-09-08'));
    }

    /**
     * Slots are computed, and the last one has to fit.
     *
     * 10:00–13:00 at 45 minutes is four slots ending at 13:00, not a fifth
     * that runs past closing.
     */
    public function test_slots_are_derived_and_the_last_one_fits(): void
    {
        [$organization] = $this->clinic();

        $this->onTenant($organization, function () {
            $service = app(AvailabilityService::class);

            $quarter = $service->slotsFor([
                'starts_at' => '10:00', 'ends_at' => '13:00', 'slot_minutes' => 15,
            ]);

            $this->assertCount(12, $quarter);
            $this->assertSame('10:00', $quarter[0]);
            $this->assertSame('12:45', end($quarter));

            $long = $service->slotsFor([
                'starts_at' => '10:00', 'ends_at' => '13:00', 'slot_minutes' => 45,
            ]);

            $this->assertSame(['10:00', '10:45', '11:30', '12:15'], $long);
        });
    }

    /** Nothing materialises a slot table — that is the whole design. */
    public function test_no_slots_are_ever_persisted(): void
    {
        [$organization, $doctorId] = $this->clinic();

        $this->sessionsOn($organization, $doctorId, self::MONDAY);

        $this->onTenant($organization, function () {
            $tables = Schema::connection('organization')
                ->getTableListing();

            $names = array_map(fn ($table) => str_replace('public.', '', $table), $tables);

            $this->assertNotContains('doctor_slots', $names);
            $this->assertNotContains('appointment_slots', $names);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Effective dates
    |--------------------------------------------------------------------------
    */

    /** A sitting that has not started yet does not produce availability. */
    public function test_a_sitting_outside_its_effective_window_does_not_apply(): void
    {
        [$organization, $doctorId] = $this->clinic();

        $this->onTenant($organization, fn () => DoctorSchedule::on('organization')
            ->where('doctor_id', $doctorId)
            ->update(['effective_from' => '2026-10-01']));

        $this->assertCount(0, $this->sessionsOn($organization, $doctorId, self::MONDAY));

        // A Monday after it starts.
        $this->assertCount(1, $this->sessionsOn($organization, $doctorId, '2026-10-05'));
    }

    /*
    |--------------------------------------------------------------------------
    | Exceptions
    |--------------------------------------------------------------------------
    */

    /**
     * Leave takes the whole day, however many sittings it had.
     *
     * This is the rule that matters most: booking a patient with a doctor
     * who is away is the failure the exception table exists to prevent.
     */
    public function test_leave_removes_the_whole_day(): void
    {
        [$organization, $doctorId] = $this->clinic();

        $this->onTenant($organization, fn () => DoctorScheduleException::on('organization')->create([
            'doctor_id' => $doctorId,
            'date' => self::MONDAY,
            'type' => DoctorScheduleException::UNAVAILABLE,
            'reason' => 'On leave',
        ]));

        $this->assertCount(0, $this->sessionsOn($organization, $doctorId, self::MONDAY));

        // And only that date — the following Monday is untouched.
        $this->assertCount(1, $this->sessionsOn($organization, $doctorId, '2026-09-14'));
    }

    /** Cancelling one sitting leaves the others alone. */
    public function test_cancelling_one_sitting_leaves_the_rest(): void
    {
        [$organization, $doctorId, $branchId] = $this->clinic();

        $eveningId = $this->onTenant($organization, fn () => DoctorSchedule::on('organization')->create([
            'doctor_id' => $doctorId,
            'location_id' => $branchId,
            'name' => 'Evening OPD',
            'weekday' => Weekday::MONDAY,
            'starts_at' => '17:00',
            'ends_at' => '20:00',
            'slot_minutes' => 15,
            'is_active' => true,
        ])->id);

        $this->assertCount(2, $this->sessionsOn($organization, $doctorId, self::MONDAY));

        $this->onTenant($organization, fn () => DoctorScheduleException::on('organization')->create([
            'doctor_id' => $doctorId,
            'doctor_schedule_id' => $eveningId,
            'date' => self::MONDAY,
            'type' => DoctorScheduleException::UNAVAILABLE,
            'reason' => 'Conference',
        ]));

        $sessions = $this->sessionsOn($organization, $doctorId, self::MONDAY);

        $this->assertCount(1, $sessions);
        $this->assertSame('Morning OPD', $sessions[0]['name']);
    }

    /** Changed hours replace the sitting's times for that date only. */
    public function test_changed_hours_apply_to_that_date_alone(): void
    {
        [$organization, $doctorId] = $this->clinic();

        $scheduleId = $this->onTenant($organization, fn () => DoctorSchedule::on('organization')
            ->where('doctor_id', $doctorId)->value('id'));

        $this->onTenant($organization, fn () => DoctorScheduleException::on('organization')->create([
            'doctor_id' => $doctorId,
            'doctor_schedule_id' => $scheduleId,
            'date' => self::MONDAY,
            'type' => DoctorScheduleException::CHANGED_HOURS,
            'starts_at' => '11:00',
            'ends_at' => '12:00',
            'reason' => 'Running late',
        ]));

        $changed = $this->sessionsOn($organization, $doctorId, self::MONDAY);
        $this->assertSame('11:00', $changed[0]['starts_at']);
        $this->assertSame('12:00', $changed[0]['ends_at']);
        $this->assertTrue($changed[0]['changed']);

        $normal = $this->sessionsOn($organization, $doctorId, '2026-09-14');
        $this->assertSame('10:00', $normal[0]['starts_at']);
    }

    /** An extra clinic appears on a day the weekly pattern says nothing about. */
    public function test_an_extra_session_appears_on_a_day_with_no_sitting(): void
    {
        [$organization, $doctorId, $branchId] = $this->clinic();

        // A Sunday, when this doctor never normally sits.
        $sunday = '2026-09-13';

        $this->assertCount(0, $this->sessionsOn($organization, $doctorId, $sunday));

        $this->onTenant($organization, fn () => DoctorScheduleException::on('organization')->create([
            'doctor_id' => $doctorId,
            'location_id' => $branchId,
            'date' => $sunday,
            'type' => DoctorScheduleException::EXTRA_SESSION,
            'starts_at' => '09:00',
            'ends_at' => '11:00',
            'slot_minutes' => 20,
            'reason' => 'Vaccination camp',
        ]));

        $sessions = $this->sessionsOn($organization, $doctorId, $sunday);

        $this->assertCount(1, $sessions);
        $this->assertSame('09:00', $sessions[0]['starts_at']);
        // No weekly row behind it, so nothing to point an appointment at.
        $this->assertNull($sessions[0]['schedule_id']);
    }

    /*
    |--------------------------------------------------------------------------
    | The API
    |--------------------------------------------------------------------------
    */

    public function test_the_day_view_lists_doctors_sitting_at_a_branch(): void
    {
        [$organization, , $branchId] = $this->clinic();
        $this->signInAsOwner($organization);

        $body = $this->getJson('/api/v1/tenant/availability/day?date='.self::MONDAY."&location_id={$branchId}")
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $body['doctors']);
        $this->assertSame('Dr. Anjali Sharma', $body['doctors'][0]['doctor_name']);
        $this->assertCount(12, $body['doctors'][0]['sessions'][0]['slots']);
    }

    /**
     * A branch id from the client is just a number until somebody checks it.
     *
     * Staff work at one branch. Nothing else in the request would have
     * stopped one of them reading — or changing — another branch's day.
     */
    public function test_staff_cannot_read_another_branchs_day(): void
    {
        [$organization, , $branchId] = $this->clinic();

        $other = $this->onTenant($organization, fn () => Location::on('organization')->create([
            'name' => 'Delhi',
            'code' => 'DEL',
            'type' => Location::CLINIC,
            'is_active' => true,
        ])->id);

        // The harness puts staff at their own branch; point them at the first.
        $this->placeStaffAt($organization, $branchId);

        $this->signInAsStaff($organization);

        $this->getJson('/api/v1/tenant/availability/day?date='.self::MONDAY."&location_id={$branchId}")
            ->assertOk();

        $this->getJson('/api/v1/tenant/availability/day?date='.self::MONDAY."&location_id={$other}")
            ->assertStatus(403);
    }

    /** The owner works across the network, so every branch is theirs. */
    public function test_the_owner_may_read_any_branchs_day(): void
    {
        [$organization, , $branchId] = $this->clinic();

        $other = $this->onTenant($organization, fn () => Location::on('organization')->create([
            'name' => 'Delhi',
            'code' => 'DEL',
            'type' => Location::CLINIC,
            'is_active' => true,
        ])->id);

        $this->signInAsOwner($organization);

        $this->getJson('/api/v1/tenant/availability/day?date='.self::MONDAY."&location_id={$branchId}")
            ->assertOk();
        $this->getJson('/api/v1/tenant/availability/day?date='.self::MONDAY."&location_id={$other}")
            ->assertOk();
    }

    /** An extra session must say where; changed hours must say which sitting. */
    public function test_each_exception_type_demands_what_it_needs(): void
    {
        [$organization, $doctorId] = $this->clinic();
        $this->signInAsOwner($organization);

        $this->postJson('/api/v1/tenant/availability/exceptions', [
            'doctor_id' => $doctorId,
            'date' => self::MONDAY,
            'type' => DoctorScheduleException::EXTRA_SESSION,
            'starts_at' => '09:00',
            'ends_at' => '11:00',
        ])->assertStatus(422)->assertJsonValidationErrors('location_id');

        $this->postJson('/api/v1/tenant/availability/exceptions', [
            'doctor_id' => $doctorId,
            'date' => self::MONDAY,
            'type' => DoctorScheduleException::CHANGED_HOURS,
            'starts_at' => '11:00',
            'ends_at' => '12:00',
        ])->assertStatus(422)->assertJsonValidationErrors('doctor_schedule_id');

        // Leave needs neither, and is accepted on its own.
        $this->postJson('/api/v1/tenant/availability/exceptions', [
            'doctor_id' => $doctorId,
            'date' => self::MONDAY,
            'type' => DoctorScheduleException::UNAVAILABLE,
            'reason' => 'On leave',
        ])->assertCreated();
    }

    public function test_staff_cannot_record_an_exception(): void
    {
        [$organization, $doctorId] = $this->clinic();
        $this->signInAsStaff($organization);

        $this->postJson('/api/v1/tenant/availability/exceptions', [
            'doctor_id' => $doctorId,
            'date' => self::MONDAY,
            'type' => DoctorScheduleException::UNAVAILABLE,
        ])->assertStatus(403);
    }

    /*
    |--------------------------------------------------------------------------
    | The week
    |--------------------------------------------------------------------------
    */

    /**
     * The week is built from the postings, not from the timetable.
     *
     * A doctor covering a branch with no hours agreed yet is exactly who the
     * screen is for: their row is seven dashes, and seven dashes is the gap
     * somebody is looking for. Deriving the rows from `doctor_schedules`
     * instead would hide them.
     */
    public function test_the_week_lists_doctors_posted_to_the_branch(): void
    {
        [$organization, $doctorId, $branchId] = $this->clinic();

        $this->onTenant($organization, function () use ($doctorId, $branchId) {
            $doctor = Doctor::on('organization')->findOrFail($doctorId);
            $doctor->postings()->syncWithoutDetaching([$branchId]);

            // Posted here, no hours anywhere.
            $spare = Doctor::on('organization')->create([
                'name' => 'Dr. Bhaskar Rao',
                'is_active' => true,
            ]);

            $spare->postings()->syncWithoutDetaching([$branchId]);
        });

        $week = $this->onTenant($organization, fn () => app(AvailabilityService::class)
            ->weekAtLocation(Carbon::parse(self::MONDAY), $branchId));

        $this->assertCount(7, $week['days']);
        $this->assertSame(self::MONDAY, $week['from']);
        $this->assertSame('2026-09-13', $week['to']);

        $names = array_column($week['doctors'], 'doctor_name');
        $this->assertSame(['Dr. Anjali Sharma', 'Dr. Bhaskar Rao'], $names);

        // The one with no timetable is present and empty all week.
        $spare = $week['doctors'][1];
        $this->assertSame(
            ['none', 'none', 'none', 'none', 'none', 'none', 'none'],
            array_column($spare['days'], 'state'),
        );
    }

    /** Somebody posted to another branch does not appear in this one's week. */
    public function test_the_week_leaves_out_doctors_posted_elsewhere(): void
    {
        [$organization, $doctorId, $branchId] = $this->clinic();

        $this->onTenant($organization, function () use ($doctorId, $branchId) {
            Doctor::on('organization')->findOrFail($doctorId)
                ->postings()->syncWithoutDetaching([$branchId]);

            $delhi = Location::on('organization')->create([
                'name' => 'Delhi',
                'code' => 'DEL',
                'type' => Location::CLINIC,
                'is_active' => true,
            ]);

            Doctor::on('organization')->create([
                'name' => 'Dr. Zoya Khan',
                'is_active' => true,
            ])->postings()->syncWithoutDetaching([$delhi->id]);
        });

        $week = $this->onTenant($organization, fn () => app(AvailabilityService::class)
            ->weekAtLocation(Carbon::parse(self::MONDAY), $branchId));

        $this->assertSame(['Dr. Anjali Sharma'], array_column($week['doctors'], 'doctor_name'));
    }

    /**
     * A day off and a cancelled day are drawn differently, and must be.
     *
     * They look identical in a diary and are opposites in practice: one is the
     * roster working as intended, the other is a doctor who was due in and is
     * not — the one that needs covering. Collapsing them into a blank cell is
     * how a cancelled Monday goes unnoticed until the patients arrive.
     */
    public function test_a_cancelled_day_reads_differently_from_a_day_off(): void
    {
        [$organization, $doctorId, $branchId] = $this->clinic();

        $this->onTenant($organization, function () use ($doctorId, $branchId) {
            Doctor::on('organization')->findOrFail($doctorId)
                ->postings()->syncWithoutDetaching([$branchId]);

            DoctorScheduleException::on('organization')->create([
                'doctor_id' => $doctorId,
                'date' => self::MONDAY,
                'type' => DoctorScheduleException::UNAVAILABLE,
                'reason' => 'On leave',
            ]);
        });

        $days = $this->onTenant($organization, fn () => app(AvailabilityService::class)
            ->weekAtLocation(Carbon::parse(self::MONDAY), $branchId))['doctors'][0]['days'];

        // Monday: rostered, and taken away — with the reason.
        $this->assertSame('unavailable', $days[0]['state']);
        $this->assertSame('On leave', $days[0]['reason']);

        // Tuesday: never rostered. An ordinary day off, not a cancellation.
        $this->assertSame('none', $days[1]['state']);
        $this->assertNull($days[1]['reason']);
    }

    /** Changed hours read as changed, because "in, but not when you think" is what gets got wrong. */
    public function test_changed_hours_mark_the_day_as_changed(): void
    {
        [$organization, $doctorId, $branchId] = $this->clinic();

        $this->onTenant($organization, function () use ($doctorId, $branchId) {
            Doctor::on('organization')->findOrFail($doctorId)
                ->postings()->syncWithoutDetaching([$branchId]);

            DoctorScheduleException::on('organization')->create([
                'doctor_id' => $doctorId,
                'doctor_schedule_id' => DoctorSchedule::on('organization')
                    ->where('doctor_id', $doctorId)->value('id'),
                'date' => self::MONDAY,
                'type' => DoctorScheduleException::CHANGED_HOURS,
                'starts_at' => '11:00',
                'ends_at' => '12:00',
                'reason' => 'Theatre list',
            ]);
        });

        $monday = $this->onTenant($organization, fn () => app(AvailabilityService::class)
            ->weekAtLocation(Carbon::parse(self::MONDAY), $branchId))['doctors'][0]['days'][0];

        $this->assertSame('changed', $monday['state']);
        $this->assertSame('Theatre list', $monday['reason']);
        $this->assertSame('11:00', $monday['sessions'][0]['starts_at']);
    }

    /** Whatever date is asked for, the week starts on its Monday. */
    public function test_the_week_snaps_back_to_monday(): void
    {
        [$organization, $doctorId, $branchId] = $this->clinic();

        $this->onTenant($organization, fn () => Doctor::on('organization')->findOrFail($doctorId)
            ->postings()->syncWithoutDetaching([$branchId]));

        $this->signInAsOwner($organization);

        // A Thursday.
        $body = $this->getJson("/api/v1/tenant/availability/week?from=2026-09-10&location_id={$branchId}")
            ->assertOk()
            ->json('data');

        $this->assertSame(self::MONDAY, $body['from']);
        $this->assertSame('2026-09-13', $body['to']);
    }

    /**
     * The branch id in the query string is a claim, not a fact.
     *
     * Same hole as the day view, one endpoint along — and the reason to assert
     * it separately is that a new endpoint is exactly where the check gets
     * left out.
     */
    public function test_staff_cannot_read_another_branchs_week(): void
    {
        [$organization, , $branchId] = $this->clinic();

        $other = $this->onTenant($organization, fn () => Location::on('organization')->create([
            'name' => 'Delhi',
            'code' => 'DEL',
            'type' => Location::CLINIC,
            'is_active' => true,
        ])->id);

        $this->placeStaffAt($organization, $branchId);
        $this->signInAsStaff($organization);

        $this->getJson("/api/v1/tenant/availability/week?from=".self::MONDAY."&location_id={$branchId}")
            ->assertOk();

        $this->getJson("/api/v1/tenant/availability/week?from=".self::MONDAY."&location_id={$other}")
            ->assertStatus(403);
    }

    public function test_availability_is_behind_the_module(): void
    {
        $organization = $this->provisionOrganization();
        $this->signInAsOwner($organization);

        $this->getJson('/api/v1/tenant/availability/day?date='.self::MONDAY.'&location_id=1')
            ->assertForbidden();
    }
}
