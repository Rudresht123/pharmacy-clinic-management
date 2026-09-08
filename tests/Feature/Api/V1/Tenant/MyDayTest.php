<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Module;
use App\Models\Platform\Organization;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Location;
use App\Support\Opd\Weekday;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TenantTestCase;

/**
 * A doctor's own day.
 *
 * The OPD board answers a manager's question — every room, every doctor. This
 * answers the two a doctor asks between patients, and the whole point of it is
 * that it is *theirs*: the doctor comes from the session, so the rules worth
 * asserting are about scope rather than about arithmetic.
 */
class MyDayTest extends TenantTestCase
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

    /**
     * A clinic with two doctors, each with a login and a Monday sitting.
     *
     * @return array{0: Organization, 1: array<string, int>, 2: int}
     */
    private function clinic(): array
    {
        $organization = $this->provisionOrganization();
        $this->enableOpd($organization);
        $this->signInAsOwner($organization);

        $branchId = $this->onTenant($organization, fn () => Location::on('organization')->create([
            'name' => 'Gurgaon',
            'code' => 'GGN',
            'type' => Location::CLINIC,
            'is_active' => true,
        ])->id);

        $ids = [];

        foreach ([
            'anjali' => 'Dr. Anjali Sharma',
            'vikram' => 'Dr. Vikram Rao',
        ] as $key => $name) {
            $doctor = $this->postJson('/api/v1/tenant/doctors', [
                'name' => $name,
                'is_active' => true,
                'account' => [
                    'email' => "{$key}@clinic.test",
                    'password' => 'doctor-secret-1',
                ],
            ])->assertCreated()->json('data');

            $ids[$key] = $doctor['id'];

            /*
             * Written through the endpoint, not straight into the table.
             *
             * Saving a sitting is also what posts a doctor to a branch, and a
             * fixture that inserts the row directly skips that — leaving a
             * doctor with a timetable at a branch they are not posted to,
             * which is a state the product cannot reach.
             */
            $this->putJson("/api/v1/tenant/doctors/{$doctor['id']}/schedules", [
                'schedules' => [[
                    'location_id' => $branchId,
                    'name' => 'Morning OPD',
                    'weekday' => Weekday::MONDAY,
                    'starts_at' => '10:00',
                    'ends_at' => '13:00',
                    'slot_minutes' => 15,
                    'is_active' => true,
                ]],
            ])->assertOk();
        }

        return [$organization, $ids, $branchId];
    }

    private function signInAsDoctor(Organization $organization, string $email): void
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/tenant/auth/login', [
            'subdomain' => $organization->subdomain,
            'email' => $email,
            'password' => 'doctor-secret-1',
        ])->assertOk();
    }

    private function book(int $customerId, int $doctorId, int $branchId, string $slot): int
    {
        return $this->postJson('/api/v1/tenant/appointments', [
            'customer_id' => $customerId,
            'doctor_id' => $doctorId,
            'location_id' => $branchId,
            'appointment_date' => self::MONDAY,
            'type' => 'booked',
            'slot_at' => $slot,
        ])->assertCreated()->json('data.id');
    }

    /**
     * The list is the signed-in doctor's, and no id can change that.
     *
     * This is the rule the endpoint exists for. Taking a doctor id from the
     * request would let one doctor read another's patients under a capability
     * meant to let them read their own — and every doctor login holds it.
     */
    public function test_a_doctor_sees_only_their_own_patients(): void
    {
        [$organization, $ids, $branchId] = $this->clinic();

        $customers = $this->onTenant($organization, fn () => [
            Customer::on('organization')->create(['name' => 'Asha Rane', 'phone' => '9810000001'])->id,
            Customer::on('organization')->create(['name' => 'Bhavin Shah', 'phone' => '9810000002'])->id,
        ]);

        $this->book($customers[0], $ids['anjali'], $branchId, '10:00');
        $this->book($customers[1], $ids['vikram'], $branchId, '10:15');

        $this->signInAsDoctor($organization, 'anjali@clinic.test');

        $body = $this->getJson('/api/v1/tenant/opd/my-day?date='.self::MONDAY)
            ->assertOk()
            ->json('data');

        $this->assertSame('Dr. Anjali Sharma', $body['doctor']['name']);

        $names = collect($body['queue'])->pluck('customer_name');
        $this->assertContains('Asha Rane', $names);
        $this->assertNotContains('Bhavin Shah', $names);
    }

    /** The counts describe that doctor's day, not the branch's. */
    public function test_the_counts_are_the_doctors_own(): void
    {
        [$organization, $ids, $branchId] = $this->clinic();

        $customers = $this->onTenant($organization, fn () => [
            Customer::on('organization')->create(['name' => 'Asha Rane', 'phone' => '9810000001'])->id,
            Customer::on('organization')->create(['name' => 'Bhavin Shah', 'phone' => '9810000002'])->id,
            Customer::on('organization')->create(['name' => 'Chetna Rao', 'phone' => '9810000003'])->id,
        ]);

        $mine = $this->book($customers[0], $ids['anjali'], $branchId, '10:00');
        $this->book($customers[1], $ids['anjali'], $branchId, '10:15');

        // Somebody else's, which must not be counted.
        $this->book($customers[2], $ids['vikram'], $branchId, '10:30');

        // One of theirs walks in and is seen.
        $this->postJson("/api/v1/tenant/appointments/{$mine}/check-in")->assertOk();

        $this->signInAsDoctor($organization, 'anjali@clinic.test');

        $counts = $this->getJson('/api/v1/tenant/opd/my-day?date='.self::MONDAY)
            ->assertOk()
            ->json('data.counts');

        $this->assertSame(2, $counts['total']);
        $this->assertSame(1, $counts['waiting']);
        $this->assertSame(1, $counts['expected']);
        $this->assertSame(0, $counts['seen']);
    }

    /**
     * Somebody who is not a doctor has no list of their own.
     *
     * The desk has the board; this is not it. Refused rather than answered
     * with an empty day, which would read as "you have no patients" instead of
     * "this screen is not for you".
     */
    public function test_a_non_doctor_has_no_day_of_their_own(): void
    {
        [$organization] = $this->clinic();

        $this->signInAsOwner($organization);

        $this->getJson('/api/v1/tenant/opd/my-day?date='.self::MONDAY)
            ->assertStatus(403);
    }

    /** Today's sittings come with it, so the strip is not a second request. */
    public function test_the_day_carries_the_doctors_sittings(): void
    {
        [$organization] = $this->clinic();

        $this->signInAsDoctor($organization, 'anjali@clinic.test');

        $schedule = $this->getJson('/api/v1/tenant/opd/my-day?date='.self::MONDAY)
            ->assertOk()
            ->json('data.schedule');

        $this->assertCount(1, $schedule);
        $this->assertSame('10:00', $schedule[0]['starts_at']);
        $this->assertSame('Morning OPD', $schedule[0]['name']);
    }

    /**
     * A doctor may act at the branches they are posted to.
     *
     * Their account is given an organization-wide role rather than a branch
     * membership, on purpose — they sit wherever their timings put them. Read
     * through memberships alone that left them able to act on nothing: signed
     * in, posted to Gurgaon, and refused at Gurgaon.
     */
    public function test_a_doctor_may_act_at_the_branch_they_are_posted_to(): void
    {
        [$organization, , $branchId] = $this->clinic();

        $elsewhere = $this->onTenant($organization, fn () => Location::on('organization')->create([
            'name' => 'Delhi',
            'code' => 'DEL',
            'type' => Location::CLINIC,
            'is_active' => true,
        ])->id);

        $this->signInAsDoctor($organization, 'anjali@clinic.test');

        // Posted here by their Monday sitting.
        $this->getJson('/api/v1/tenant/availability/day?date='.self::MONDAY."&location_id={$branchId}")
            ->assertOk();

        // Never posted there.
        $this->getJson('/api/v1/tenant/availability/day?date='.self::MONDAY."&location_id={$elsewhere}")
            ->assertStatus(403);
    }
}
