<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\LabDepartment;
use App\Models\Tenant\LabOrder;
use App\Models\Tenant\LabOrderItem;
use App\Models\Tenant\LabTest;
use App\Models\Tenant\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TenantTestCase;

/**
 * The lab's daily screens built from the user's reference design (2026-11-08):
 * the Laboratory Dashboard, the patient test list and a request's details —
 * every figure counted from real orders, at the branches a person may see.
 */
class LabWorkScreensTest extends TenantTestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private int $branch;

    private int $otherBranch;

    private int $haematology;

    /** @var array<string, int> */
    private array $orders = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-08 10:00:00');

        $this->artisan('modules:sync');

        $this->organization = $this->provisionOrganization('LWS');
        $this->grantModule($this->organization, 'laboratory');

        $this->onTenant($this->organization, function () {
            $this->branch = Location::on('organization')->create(['name' => 'Main Lab', 'code' => 'LWS-1', 'type' => Location::CLINIC, 'is_active' => true])->id;
            $this->otherBranch = Location::on('organization')->create(['name' => 'Second Lab', 'code' => 'LWS-2', 'type' => Location::CLINIC, 'is_active' => true])->id;
            $this->haematology = LabDepartment::on('organization')->create(['name' => 'Haematology'])->id;

            $cbc = LabTest::on('organization')->create([
                'code' => 'CBC', 'name' => 'CBC', 'department_id' => $this->haematology, 'price' => 300,
            ])->id;

            $doctor = Doctor::on('organization')->create(['name' => 'Dr. Mehta', 'is_active' => true])->id;
            $asha = Customer::on('organization')->create([
                'name' => 'Asha Rane', 'phone' => '9810000101', 'is_active' => true,
                'gender' => 'female', 'date_of_birth' => '1992-03-01',
            ])->id;
            $ravi = Customer::on('organization')->create(['name' => 'Ravi Kumar', 'phone' => '9810000102', 'is_active' => true])->id;

            $today = '2026-11-08';
            $old = '2026-09-29';

            $this->orders['done'] = $this->order($asha, $doctor, $this->branch, $today, LabOrder::COMPLETED, [
                ['CBC', $cbc, LabOrderItem::COMPLETED, false],
                ['ESR', null, LabOrderItem::COMPLETED, true],
            ]);
            $this->orders['waiting'] = $this->order($ravi, $doctor, $this->branch, $today, LabOrder::PENDING, [
                ['TSH', null, LabOrderItem::PENDING, false],
            ]);
            $this->orders['old'] = $this->order($asha, $doctor, $this->branch, $old, LabOrder::COMPLETED, [
                ['Lipid profile', null, LabOrderItem::COMPLETED, false],
            ]);
            $this->orders['elsewhere'] = $this->order($ravi, $doctor, $this->otherBranch, $today, LabOrder::PENDING, [
                ['HbA1c', null, LabOrderItem::PENDING, false],
            ]);
            $this->orders['withdrawn'] = $this->order($ravi, $doctor, $this->branch, $today, LabOrder::CANCELLED, [
                ['Vitamin D', null, LabOrderItem::CANCELLED, false],
            ]);
        });

        $this->placeStaffAt($this->organization, $this->branch);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * An order exactly as the lab would have left it, on its own visit.
     *
     * @param  list<array{0: string, 1: int|null, 2: string, 3: bool}>  $tests
     */
    private function order(int $patient, int $doctor, int $branch, string $date, string $status, array $tests): int
    {
        $at = Carbon::parse($date.' 09:00:00');

        $visit = Appointment::on('organization')->create([
            'customer_id' => $patient, 'doctor_id' => $doctor, 'location_id' => $branch,
            'appointment_date' => $date, 'type' => Appointment::BOOKED, 'status' => Appointment::STATUS_CHECKED_IN,
        ]);

        $order = LabOrder::on('organization')->create([
            'location_id' => $branch, 'customer_id' => $patient, 'doctor_id' => $doctor,
            'appointment_id' => $visit->id, 'order_date' => $date,
        ]);

        $order->forceFill([
            'status' => $status,
            'started_at' => in_array($status, [LabOrder::IN_PROGRESS, LabOrder::COMPLETED], true) ? $at : null,
            'completed_at' => $status === LabOrder::COMPLETED ? $at : null,
            'cancelled_at' => $status === LabOrder::CANCELLED ? $at : null,
            'cancellation_reason' => $status === LabOrder::CANCELLED ? 'Patient left' : null,
            'created_at' => $at,
        ])->save();

        foreach ($tests as $index => [$name, $testId, $itemStatus, $abnormal]) {
            $item = $order->items()->create(['test_name' => $name, 'lab_test_id' => $testId, 'sort_order' => $index, 'specimen' => 'blood']);

            if ($itemStatus !== LabOrderItem::PENDING) {
                $item->forceFill([
                    'status' => $itemStatus,
                    'completed_at' => $itemStatus === LabOrderItem::COMPLETED ? $at : null,
                    'result_value' => $itemStatus === LabOrderItem::COMPLETED ? '12' : null,
                    'is_abnormal' => $abnormal,
                ])->save();
            }
        }

        return $order->id;
    }

    /** @param list<string> $ticks */
    private function staffHolding(array $ticks): void
    {
        $this->setStaffCapabilities($this->organization, $ticks);
        $this->signInAsStaff($this->organization);
    }

    /* ------------------------------------------------------------------
     | Dashboard
     |------------------------------------------------------------------ */

    public function test_the_dashboard_counts_this_branchs_tests(): void
    {
        $this->staffHolding(['laboratory.dashboard']);

        $data = $this->getJson('/api/v1/tenant/lab/dashboard?range=30d')->assertOk()->json('data');

        // CBC, ESR, TSH and the cancelled Vitamin D in the last 30 days; the
        // lipid profile 40 days ago was the period before. Not the other branch's.
        $this->assertSame(4, $data['kpis']['ordered']['value']);
        $this->assertSame(1, $data['kpis']['ordered']['previous']);
        $this->assertEquals(300, $data['kpis']['ordered']['change']);
        $this->assertSame(2, $data['kpis']['completed']['value']);
        $this->assertSame(['value' => 1, 'orders' => 1], $data['kpis']['pending']);
        $this->assertSame(1, $data['kpis']['cancelled']['value']);
        $this->assertNull($data['kpis']['cancelled']['change']);

        // The trend: everything today, in the last bucket.
        $this->assertCount(30, $data['overview']['points']);
        $this->assertSame('2026-11-08', $data['overview']['points'][29]['key']);
        $this->assertSame(4, $data['overview']['ordered'][29]);
        $this->assertSame(2, $data['overview']['completed'][29]);

        // By department — the typed tests have none — and by name; cancelled left out.
        $this->assertEqualsCanonicalizing(
            [['Typed tests (no department)', 2], ['Haematology', 1]],
            array_map(fn ($slice) => [$slice['label'], $slice['value']], $data['departments']),
        );
        $this->assertCount(3, $data['top_tests']);

        $this->assertSame(
            [$this->orders['withdrawn'], $this->orders['waiting'], $this->orders['done'], $this->orders['old']],
            array_column($data['recent'], 'id'),
        );
    }

    public function test_the_owner_sees_every_branch_and_the_range_is_checked(): void
    {
        $this->signInAsOwner($this->organization);

        $this->getJson('/api/v1/tenant/lab/dashboard')
            ->assertOk()
            ->assertJsonPath('data.range', '30d')
            ->assertJsonPath('data.kpis.pending.value', 2)
            ->assertJsonPath('data.kpis.ordered.value', 5);

        $this->getJson('/api/v1/tenant/lab/dashboard?range=1y')
            ->assertOk()
            ->assertJsonCount(12, 'data.overview.points')
            ->assertJsonPath('data.kpis.ordered.value', 6);

        $this->getJson('/api/v1/tenant/lab/dashboard?range=5y')->assertStatus(422);
    }

    public function test_the_dashboard_is_its_own_tick(): void
    {
        $this->staffHolding(['laboratory.view']);
        $this->getJson('/api/v1/tenant/lab/dashboard')->assertForbidden();

        $this->disableModuleAtBranch($this->organization, $this->branch, 'laboratory');
        $this->staffHolding(['laboratory.dashboard']);
        $this->getJson('/api/v1/tenant/lab/dashboard')->assertForbidden();
    }

    /* ------------------------------------------------------------------
     | The test list and a request's details
     |------------------------------------------------------------------ */

    public function test_the_list_searches_patients_and_tests_and_filters(): void
    {
        $this->staffHolding(['laboratory.view']);

        $ids = fn (string $query) => array_column($this->getJson('/api/v1/tenant/lab-orders?'.$query)->assertOk()->json('data'), 'id');

        // Only this branch's four, newest first.
        $this->assertSame(
            [$this->orders['withdrawn'], $this->orders['waiting'], $this->orders['done'], $this->orders['old']],
            $ids('sort=created_at&direction=desc'),
        );

        $this->assertEqualsCanonicalizing([$this->orders['done'], $this->orders['old']], $ids('search=asha'));
        $this->assertSame([$this->orders['waiting']], $ids('search=tsh'));
        $this->assertSame([$this->orders['done']], $ids("department_id={$this->haematology}"));
        $this->assertSame([$this->orders['old']], $ids('date_from=2026-09-01&date_to=2026-10-01'));
        $this->assertSame([$this->orders['waiting']], $ids('status=pending'));

        $this->getJson('/api/v1/tenant/lab-orders?date_from=2026-11-08&date_to=2026-11-01')->assertStatus(422);
    }

    public function test_the_tabs_count_each_status_with_the_same_filters(): void
    {
        $this->staffHolding(['laboratory.view']);

        $this->getJson('/api/v1/tenant/lab-orders/counts')
            ->assertOk()
            ->assertExactJson(['data' => ['all' => 4, 'pending' => 1, 'in_progress' => 0, 'completed' => 2, 'cancelled' => 1]]);

        $this->getJson('/api/v1/tenant/lab-orders/counts?search=asha')
            ->assertJsonPath('data.all', 2)
            ->assertJsonPath('data.completed', 2)
            ->assertJsonPath('data.pending', 0);
    }

    public function test_a_request_shows_the_patient_departments_samples_and_flags(): void
    {
        $this->staffHolding(['laboratory.view']);

        $this->getJson("/api/v1/tenant/lab-orders/{$this->orders['done']}")
            ->assertOk()
            ->assertJsonPath('data.patient_name', 'Asha Rane')
            ->assertJsonPath('data.patient_gender', 'female')
            ->assertJsonPath('data.patient_age', 34)
            ->assertJsonPath('data.departments', ['Haematology'])
            ->assertJsonPath('data.sample_types', ['Blood'])
            ->assertJsonPath('data.abnormal_count', 1)
            ->assertJsonPath('data.items.0.department_name', 'Haematology');

        // Another branch's request is not this person's to open.
        $this->getJson("/api/v1/tenant/lab-orders/{$this->orders['elsewhere']}")->assertForbidden();
    }

    public function test_the_list_needs_its_tick(): void
    {
        $this->staffHolding(['laboratory.dashboard']);

        $this->getJson('/api/v1/tenant/lab-orders')->assertForbidden();
        $this->getJson('/api/v1/tenant/lab-orders/counts')->assertForbidden();
    }
}
