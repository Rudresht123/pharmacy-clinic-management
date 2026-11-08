<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Consultation;
use App\Models\Tenant\Customer;
use App\Models\Tenant\LabDepartment;
use App\Models\Tenant\LabOrderItem;
use App\Models\Tenant\LabTest;
use App\Models\Tenant\Location;
use App\Support\Opd\Weekday;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TenantTestCase;

/**
 * The doctor's Investigations as real lab orders (decision D1, built
 * 2026-11-08 at the user's ask: "doctor test kaise assign karega").
 *
 * A test picked from the catalogue (or typed) with a note becomes a line on
 * the visit's lab order, priced from the catalogue; more tests go on the same
 * order while the lab has not taken it up; and the consultation's own
 * investigations list follows the order, so the patient file and "My
 * investigations" keep showing what the doctor asked for.
 */
class LabDoctorOrderingTest extends TenantTestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-09-07';

    private const DOCTOR_PASSWORD = 'doctor-secret-1';

    private Organization $organization;

    private int $branch;

    private int $patient;

    private int $doctor;

    private int $cbc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');

        $this->organization = $this->provisionOrganization('LDO');

        foreach (['appointments', 'laboratory'] as $module) {
            $this->grantModule($this->organization, $module);
        }

        $this->onTenant($this->organization, function () {
            $this->branch = Location::on('organization')->create([
                'name' => 'Gurgaon', 'code' => 'LDO-GGN', 'type' => Location::CLINIC, 'is_active' => true,
            ])->id;

            $this->patient = Customer::on('organization')->create([
                'name' => 'Asha Rane', 'phone' => '9810000201', 'is_active' => true,
            ])->id;

            $this->cbc = LabTest::on('organization')->create([
                'code' => 'CBC', 'name' => 'Complete Blood Count',
                'department_id' => LabDepartment::on('organization')->create(['name' => 'Haematology'])->id,
                'price' => 300,
            ])->id;
        });

        $this->signInAsOwner($this->organization);

        $this->doctor = (int) $this->postJson('/api/v1/tenant/doctors', [
            'name' => 'Dr. Anjali Sharma',
            'is_active' => true,
            'account' => ['email' => 'anjali@clinic.test', 'password' => self::DOCTOR_PASSWORD],
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/v1/tenant/doctors/{$this->doctor}/schedules", [
            'schedules' => [[
                'location_id' => $this->branch,
                'name' => 'Morning OPD',
                'weekday' => Weekday::MONDAY,
                'starts_at' => '10:00',
                'ends_at' => '13:00',
                'slot_minutes' => 15,
                'is_active' => true,
            ]],
        ])->assertOk();
    }

    /** Booked, arrived, called and taken in — the session is left with the doctor. */
    private function visitInProgress(): int
    {
        $this->signInAsOwner($this->organization);

        $id = (int) $this->postJson('/api/v1/tenant/appointments', [
            'customer_id' => $this->patient,
            'doctor_id' => $this->doctor,
            'location_id' => $this->branch,
            'appointment_date' => self::MONDAY,
            'type' => Appointment::BOOKED,
            'slot_at' => '10:00',
        ])->assertCreated()->json('data.id');

        $this->checkInAtDesk($this->organization, $this->branch, $id);
        $this->postJson("/api/v1/tenant/appointments/{$id}/call")->assertOk();

        $this->signInAsDoctor();
        $this->postJson("/api/v1/tenant/appointments/{$id}/consultation/start")->assertOk();

        return $id;
    }

    private function signInAsDoctor(): void
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/tenant/auth/login', [
            'subdomain' => $this->organization->subdomain,
            'email' => 'anjali@clinic.test',
            'password' => self::DOCTOR_PASSWORD,
        ])->assertOk();
    }

    /** @return list<array<string, mixed>> */
    private function investigations(int $visit): array
    {
        return $this->onTenant($this->organization, fn () => Consultation::on('organization')
            ->where('appointment_id', $visit)
            ->value('investigations') ?? []);
    }

    public function test_the_doctor_sends_catalogue_and_typed_tests_with_notes(): void
    {
        $visit = $this->visitInProgress();

        // Typed into the old note box earlier: "cbc", "sugar test" and an ECG.
        $this->putJson("/api/v1/tenant/appointments/{$visit}/consultation", [
            'chief_complaint' => 'Fatigue',
            'investigations' => [
                ['test' => 'cbc', 'notes' => 'old note'],
                ['test' => 'sugar test', 'notes' => null],
                ['test' => 'ECG', 'notes' => null],
            ],
        ])->assertOk();

        // The doctor's picker reads the catalogue.
        $this->getJson('/api/v1/tenant/lab/orderables?search=blood')
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->cbc);

        $order = $this->postJson('/api/v1/tenant/lab-orders', [
            'appointment_id' => $visit,
            'items' => [
                ['test_name' => 'Complete Blood Count', 'lab_test_id' => $this->cbc, 'instructions' => 'Fasting'],
                ['test_name' => 'Vitamin D', 'instructions' => '  '],
                // The doctor's screen says which typed line this test replaces.
                ['test_name' => 'Fasting blood sugar', 'replaces' => 'sugar test'],
            ],
        ])->assertCreated()->json('data');

        $this->assertSame('pending', $order['status']);
        $this->assertSame('Fasting', $order['items'][0]['instructions']);
        $this->assertSame($this->cbc, $order['items'][0]['lab_test_id']);
        $this->assertNull($order['items'][1]['instructions']);

        // Priced from the catalogue; the typed test carries no charge.
        $prices = $this->onTenant($this->organization, fn () => LabOrderItem::on('organization')
            ->where('lab_order_id', $order['id'])->orderBy('sort_order')->pluck('price_snapshot')->all());
        $this->assertEquals(['300.00', null, null], $prices);

        // The consultation follows: "cbc" is the sent CBC (its code), "sugar test"
        // what the screen said it replaced; the ECG was never sent and stays typed.
        $lines = $this->investigations($visit);
        $this->assertSame(['ECG', 'Complete Blood Count', 'Vitamin D', 'Fasting blood sugar'], array_column($lines, 'test'));
        $this->assertSame('Fasting', $lines[1]['notes']);
        $this->assertSame($order['items'][0]['id'], $lines[1]['lab_order_item_id']);
        $this->assertArrayNotHasKey('lab_order_item_id', $lines[0]);

        // It is on the lab's list, with the doctor's note.
        $this->getJson("/api/v1/tenant/lab-orders/{$order['id']}")
            ->assertOk()
            ->assertJsonPath('data.items.0.instructions', 'Fasting')
            ->assertJsonPath('data.doctor_id', $this->doctor);
    }

    public function test_more_tests_go_on_the_same_order_until_the_lab_takes_it_up(): void
    {
        $visit = $this->visitInProgress();

        $order = $this->postJson('/api/v1/tenant/lab-orders', [
            'appointment_id' => $visit,
            'items' => [['test_name' => 'Complete Blood Count', 'lab_test_id' => $this->cbc]],
        ])->assertCreated()->json('data');

        $more = $this->putJson("/api/v1/tenant/lab-orders/{$order['id']}", [
            'items' => [
                ['id' => $order['items'][0]['id'], 'test_name' => 'Complete Blood Count', 'lab_test_id' => $this->cbc, 'instructions' => 'Fasting'],
                ['test_name' => 'TSH'],
            ],
        ])->assertOk()->json('data');

        $this->assertCount(2, $more['items']);
        $this->assertSame('Fasting', $more['items'][0]['instructions']);
        $this->assertSame(['Complete Blood Count', 'TSH'], array_column($this->investigations($visit), 'test'));

        // Taking a test back off keeps the consultation in step.
        $this->putJson("/api/v1/tenant/lab-orders/{$order['id']}", [
            'items' => [['id' => $order['items'][0]['id'], 'test_name' => 'Complete Blood Count', 'lab_test_id' => $this->cbc]],
        ])->assertOk();

        $this->assertSame(['Complete Blood Count'], array_column($this->investigations($visit), 'test'));

        // "Save notes" without the list leaves the lab's lines where they are.
        $this->putJson("/api/v1/tenant/appointments/{$visit}/consultation", ['chief_complaint' => 'Fatigue, better'])->assertOk();
        $this->assertSame(['Complete Blood Count'], array_column($this->investigations($visit), 'test'));

        // Once the lab has taken it up, the order no longer changes.
        $this->setStaffCapabilities($this->organization, ['laboratory.view', 'laboratory.process']);
        $this->placeStaffAt($this->organization, $this->branch);
        $this->signInAsStaff($this->organization);
        $this->postJson("/api/v1/tenant/lab-orders/{$order['id']}/process")->assertOk();

        $this->signInAsDoctor();
        $this->putJson("/api/v1/tenant/lab-orders/{$order['id']}", ['items' => [['test_name' => 'ESR']]])->assertStatus(409);
    }

    public function test_only_the_visits_own_doctor_orders(): void
    {
        $visit = $this->visitInProgress();

        $this->signInAsOwner($this->organization);

        $this->postJson('/api/v1/tenant/lab-orders', [
            'appointment_id' => $visit,
            'items' => [['test_name' => 'CBC']],
        ])->assertForbidden();
    }
}
