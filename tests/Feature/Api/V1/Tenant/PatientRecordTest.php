<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\InvoiceItem;
use App\Models\Tenant\Location;
use App\Services\Billing\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TenantTestCase;

/**
 * Patient Records — one patient's history from every module, in one place.
 *
 * What is asserted: the owner sees one patient's records at every branch as
 * one history, linked by the patient; staff see only their own branches'; a
 * section the caller cannot read comes back absent rather than as zero; the
 * register lists the most recently seen first; one patient's payments can be
 * read from the payments register; and the timeline is newest first across
 * modules, with nothing still to come.
 */
class PatientRecordTest extends TenantTestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private int $gurgaon;

    private int $delhi;

    private int $asha;

    private int $ravi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-15 11:00:00');

        $this->artisan('modules:sync');

        $this->organization = $this->provisionOrganization('PRC');
        $this->grantModule($this->organization, 'appointments');
        $this->allowPartPayments($this->organization);

        $this->onTenant($this->organization, function () {
            $branch = fn (string $name, string $code) => Location::on('organization')->create([
                'name' => $name, 'code' => $code, 'type' => Location::CLINIC, 'is_active' => true,
            ])->id;

            $this->gurgaon = $branch('Gurgaon', 'PRC-GGN');
            $this->delhi = $branch('Delhi', 'PRC-DEL');

            $this->asha = Customer::on('organization')->create([
                'name' => 'Asha Verma', 'phone' => '9811100001', 'registered_location_id' => $this->gurgaon,
            ])->id;
            $this->ravi = Customer::on('organization')->create([
                'name' => 'Ravi Kumar', 'phone' => '9811100002',
            ])->id;

            $doctor = Doctor::on('organization')->create(['name' => 'Dr. Mehta', 'is_active' => true])->id;

            $visit = fn (int $branch, string $date, string $status) => Appointment::on('organization')->create([
                'customer_id' => $this->asha,
                'doctor_id' => $doctor,
                'location_id' => $branch,
                'appointment_date' => $date,
                'type' => Appointment::BOOKED,
                'status' => $status,
            ]);

            // Asha is seen at both branches, missed one, and is booked again.
            $visit($this->delhi, '2026-09-01', Appointment::STATUS_CANCELLED);
            $visit($this->gurgaon, '2026-10-01', Appointment::STATUS_CHECKED_IN);
            $visit($this->delhi, '2026-10-10', Appointment::STATUS_CHECKED_IN);
            $visit($this->gurgaon, '2026-10-20', Appointment::STATUS_BOOKED);

            $billing = app(BillingService::class);

            $bill = fn (int $branch, float $price) => $billing->draw(
                [
                    'location_id' => $branch,
                    'customer_id' => $this->asha,
                    'trigger' => 'manual',
                    'kind' => Invoice::KIND_MANUAL,
                ],
                [['source_type' => InvoiceItem::SOURCE_CUSTOM, 'description' => 'Dressing', 'unit_price' => $price]],
            );

            $billing->recordPayment($bill($this->gurgaon, 500)->fresh(), ['method' => 'cash', 'amount' => 200]);
            $bill($this->delhi, 300);
        });
    }

    private function record(int $customer, ?int $branch = null)
    {
        $request = $branch ? $this->withHeader('X-Branch-Id', (string) $branch) : $this;

        return $request->getJson("/api/v1/tenant/patient-records/{$customer}")->assertOk()->json('data');
    }

    /** Branch staff on the seeded role, holding exactly these capabilities. */
    private function staffAtGurgaon(array $capabilities): void
    {
        $this->placeStaffAt($this->organization, $this->gurgaon);
        $this->setStaffCapabilities($this->organization, $capabilities);
        $this->signInAsStaff($this->organization);
    }

    public function test_the_owner_sees_one_patient_across_every_branch(): void
    {
        $this->signInAsOwner($this->organization);

        $record = $this->record($this->asha);

        $this->assertSame('Asha Verma', $record['patient']['name']);
        $this->assertSame('Gurgaon', $record['patient']['registered_location']['name']);

        $this->assertSame(4, $record['sections']['appointments']);
        $this->assertSame(2, $record['sections']['visits']);
        $this->assertSame(2, $record['sections']['billing']);

        // Linked by the patient, whichever branch the record was made at.
        $this->assertSame(['Delhi', 'Gurgaon'], array_column($record['branches'], 'name'));

        $this->assertEquals(['billed' => 800, 'paid' => 200, 'outstanding' => 600], $record['billing']);
        $this->assertSame('2026-10-10', $record['last_visit']['on']);
        $this->assertSame('Delhi', $record['last_visit']['location_name']);
        $this->assertSame('2026-10-20', $record['next_visit']['on']);
    }

    public function test_staff_see_only_their_own_branches_records(): void
    {
        $this->staffAtGurgaon(['customers.view', 'billing.view']);

        $record = $this->record($this->asha, $this->gurgaon);

        $this->assertSame(2, $record['sections']['appointments']);
        $this->assertSame(1, $record['sections']['visits']);
        $this->assertSame(1, $record['sections']['billing']);
        $this->assertSame(['Gurgaon'], array_column($record['branches'], 'name'));
        $this->assertEquals(['billed' => 500, 'paid' => 200, 'outstanding' => 300], $record['billing']);
        $this->assertSame('2026-10-01', $record['last_visit']['on']);

        $visits = $this->withHeader('X-Branch-Id', (string) $this->gurgaon)
            ->getJson("/api/v1/tenant/patient-records/{$this->asha}/appointments?view=visits")
            ->assertOk()
            ->json('data');

        $this->assertSame(['2026-10-01'], array_column($visits, 'appointment_date'));

        // Even asked for by name, Delhi's visits are not theirs to read.
        $this->assertSame([], $this->withHeader('X-Branch-Id', (string) $this->gurgaon)
            ->getJson("/api/v1/tenant/patient-records/{$this->asha}/appointments?location_id={$this->delhi}")
            ->assertOk()
            ->json('data'));

        $this->assertNotContains('Delhi', array_column($record['activity'], 'location_name'));
    }

    public function test_a_section_the_caller_cannot_read_is_absent_not_zero(): void
    {
        $this->staffAtGurgaon(['customers.view']);

        $record = $this->record($this->asha, $this->gurgaon);

        $this->assertSame(2, $record['sections']['appointments']);
        $this->assertNull($record['sections']['billing']);
        $this->assertNull($record['billing']);
        // Never sold to this organization at all.
        $this->assertNull($record['sections']['pharmacy']);
        $this->assertNull($record['sections']['prescriptions']);

        $this->assertNotContains('invoice', array_column($record['activity'], 'kind'));

        $this->withHeader('X-Branch-Id', (string) $this->gurgaon)
            ->getJson("/api/v1/tenant/patient-records/{$this->asha}/pharmacy")
            ->assertForbidden();
    }

    public function test_the_register_lists_the_most_recently_seen_first(): void
    {
        $this->signInAsOwner($this->organization);

        $rows = $this->getJson('/api/v1/tenant/patient-records')->assertOk()->json('data');

        $this->assertSame([$this->asha, $this->ravi], array_column($rows, 'id'));
        $this->assertSame('2026-10-10', $rows[0]['last_visit_on']);
        $this->assertNull($rows[1]['last_visit_on']);

        $found = $this->getJson('/api/v1/tenant/patient-records?search=9811100002')->assertOk()->json('data');

        $this->assertSame([$this->ravi], array_column($found, 'id'));

        $cards = $this->getJson('/api/v1/tenant/patient-records/stats')->assertOk()->json('data');

        $this->assertSame(
            ['total' => 2, 'new' => 2, 'upcoming' => 1, 'recent' => 2],
            array_column($cards['cards'], 'value', 'key'),
        );
        $this->assertSame(['Delhi', 'Gurgaon'], array_column($cards['branches'], 'name'));
    }

    public function test_the_register_downloads_as_csv_with_the_lists_own_search(): void
    {
        $this->signInAsOwner($this->organization);

        $all = $this->get('/api/v1/tenant/patient-records/export?format=csv')->assertOk();

        $this->assertStringStartsWith('text/csv', $all->headers->get('Content-Type'));
        $this->assertStringContainsString('patients-2026-10-15.csv', $all->headers->get('Content-Disposition'));

        $lines = array_map('str_getcsv', array_filter(explode("\n", ltrim($all->streamedContent(), "\xEF\xBB\xBF"))));

        $this->assertContains('Last visit', $lines[0]);
        // Most recently seen first, as on screen; Asha's last visit as it reads.
        $this->assertSame(['Asha Verma', 'Ravi Kumar'], array_column(array_slice($lines, 1), 1));
        $this->assertSame('10 Oct 2026', $lines[1][array_search('Last visit', $lines[0], true)]);
        $this->assertSame('Gurgaon', $lines[1][array_search('Registered at', $lines[0], true)]);

        $found = $this->get('/api/v1/tenant/patient-records/export?format=csv&search=9811100002')->assertOk()->streamedContent();

        $this->assertStringContainsString('Ravi Kumar', $found);
        $this->assertStringNotContainsString('Asha Verma', $found);
    }

    public function test_the_register_downloads_as_excel_and_pdf(): void
    {
        $this->signInAsOwner($this->organization);

        $excel = $this->get('/api/v1/tenant/patient-records/export?format=xlsx')->assertOk();

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($excel->baseResponse->getFile()->getPathname()));
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        $this->assertStringContainsString('Asha Verma', $sheet);
        $this->assertStringContainsString('Ravi Kumar', $sheet);

        $pdf = $this->get('/api/v1/tenant/patient-records/export?format=pdf')->assertOk();

        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $this->getJson('/api/v1/tenant/patient-records/export?format=doc')->assertUnprocessable();
    }

    public function test_exporting_is_its_own_permission(): void
    {
        // Reading the register is not taking it away.
        $this->staffAtGurgaon(['customers.view']);

        $this->withHeader('X-Branch-Id', (string) $this->gurgaon)
            ->getJson('/api/v1/tenant/patient-records/export?format=csv')
            ->assertForbidden();

        // Given the Export Center's patient tab on their role, the same person may.
        $this->setStaffCapabilities($this->organization, ['customers.view', 'exports.patients']);
        $this->signInAsStaff($this->organization);

        $this->withHeader('X-Branch-Id', (string) $this->gurgaon)
            ->get('/api/v1/tenant/patient-records/export?format=csv')
            ->assertOk();
    }

    public function test_one_patients_payments_can_be_read_from_the_register(): void
    {
        $this->signInAsOwner($this->organization);

        $asha = $this->getJson("/api/v1/tenant/billing/payments?customer_id={$this->asha}")->assertOk()->json('data');
        $ravi = $this->getJson("/api/v1/tenant/billing/payments?customer_id={$this->ravi}")->assertOk()->json('data');

        $this->assertEquals([200], array_column($asha, 'amount'));
        $this->assertSame([], $ravi);
    }

    public function test_the_timeline_is_newest_first_across_modules(): void
    {
        $this->signInAsOwner($this->organization);

        $events = $this->getJson("/api/v1/tenant/patient-records/{$this->asha}/timeline")
            ->assertOk()
            ->json('data');

        // Today's two bills, the two visits, the missed booking — and not
        // the booking still to come.
        $this->assertSame(['invoice', 'invoice', 'visit', 'visit', 'appointment'], array_column($events, 'kind'));
        $this->assertSame('cancelled', $events[4]['status']);
        $this->assertFalse($events[2]['has_time']);
        $this->assertStringStartsWith('/billing/invoices/', $events[0]['link']);
    }
}
