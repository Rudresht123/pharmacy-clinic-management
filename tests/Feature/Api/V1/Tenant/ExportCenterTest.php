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
use ZipArchive;

/**
 * The Export Center.
 *
 * What is asserted: a tab is offered only where a role gives it and the
 * organization runs its module; a branch role exports its own branch and an
 * organization role every branch; communication is organization-level only;
 * a role's column rules narrow what its holders take — in the Export Center
 * and on the Patient Records button alike; and the three formats carry the
 * filtered rows, Excel and PDF with totals under the money.
 */
class ExportCenterTest extends TenantTestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private int $gurgaon;

    private int $delhi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-15 11:00:00');

        $this->artisan('modules:sync');

        $this->organization = $this->provisionOrganization('EXC');
        $this->grantModule($this->organization, 'appointments');
        $this->allowPartPayments($this->organization);

        $this->onTenant($this->organization, function () {
            $branch = fn (string $name, string $code) => Location::on('organization')->create([
                'name' => $name, 'code' => $code, 'type' => Location::CLINIC, 'is_active' => true,
            ])->id;

            $this->gurgaon = $branch('Gurgaon', 'EXC-GGN');
            $this->delhi = $branch('Delhi', 'EXC-DEL');

            $asha = Customer::on('organization')->create([
                'name' => 'Asha Verma', 'phone' => '9811100001', 'email' => 'asha@example.com', 'registered_location_id' => $this->gurgaon,
            ])->id;
            $ravi = Customer::on('organization')->create([
                'name' => 'Ravi Kumar', 'phone' => '9811100002', 'registered_location_id' => $this->delhi,
            ])->id;

            $doctor = Doctor::on('organization')->create(['name' => 'Dr. Mehta', 'is_active' => true])->id;

            foreach ([[$asha, $this->gurgaon], [$ravi, $this->delhi]] as [$patient, $at]) {
                Appointment::on('organization')->create([
                    'customer_id' => $patient,
                    'doctor_id' => $doctor,
                    'location_id' => $at,
                    'appointment_date' => '2026-10-10',
                    'type' => Appointment::BOOKED,
                    'status' => Appointment::STATUS_CHECKED_IN,
                ]);
            }

            $billing = app(BillingService::class);

            $bill = fn (int $at, int $patient, float $price) => $billing->draw(
                ['location_id' => $at, 'customer_id' => $patient, 'trigger' => 'manual', 'kind' => Invoice::KIND_MANUAL],
                [['source_type' => InvoiceItem::SOURCE_CUSTOM, 'description' => 'Dressing', 'unit_price' => $price]],
            );

            $billing->recordPayment($bill($this->gurgaon, $asha, 500)->fresh(), ['method' => 'cash', 'amount' => 200]);
            $bill($this->delhi, $ravi, 300);
        });
    }

    /** @return array<string, mixed> */
    private function catalogue(?int $branch = null): array
    {
        $request = $branch ? $this->withHeader('X-Branch-Id', (string) $branch) : $this;

        return $request->getJson('/api/v1/tenant/exports')->assertOk()->json('data');
    }

    /** A branch role at Gurgaon holding exactly these capabilities. */
    private function staffAtGurgaon(array $capabilities): void
    {
        $this->placeStaffAt($this->organization, $this->gurgaon);
        $this->setStaffCapabilities($this->organization, $capabilities);
        $this->signInAsStaff($this->organization);
    }

    public function test_the_owner_is_offered_every_tab_the_organization_runs(): void
    {
        $this->signInAsOwner($this->organization);

        $data = $this->catalogue();
        $tabs = collect($data['tabs'])->keyBy('key');

        // Pharmacy and communication were never sold, so they are not tabs at all.
        $this->assertSame(['patients', 'billing'], $tabs->keys()->all());

        // Prescriptions and the lab need modules of their own.
        $this->assertSame(['patients.register', 'patients.visits'], array_column($tabs['patients']['datasets'], 'key'));
        $this->assertTrue($tabs['billing']['all_branches']);
        $this->assertSame(['Delhi', 'Gurgaon'], array_column($tabs['billing']['branches'], 'name'));
        $this->assertTrue($data['can_manage_columns']);
    }

    public function test_nothing_is_offered_without_an_export_capability(): void
    {
        $this->staffAtGurgaon(['customers.view', 'billing.view']);

        $this->assertSame([], $this->catalogue($this->gurgaon)['tabs']);

        $this->withHeader('X-Branch-Id', (string) $this->gurgaon)
            ->getJson('/api/v1/tenant/exports/billing.invoices/preview')
            ->assertForbidden();
    }

    public function test_a_branch_role_exports_its_own_branch_only(): void
    {
        $this->staffAtGurgaon(['exports.billing']);

        $tabs = $this->catalogue($this->gurgaon)['tabs'];

        $this->assertSame(['billing'], array_column($tabs, 'key'));
        $this->assertFalse($tabs[0]['all_branches']);
        $this->assertSame(['Gurgaon'], array_column($tabs[0]['branches'], 'name'));

        $preview = $this->withHeader('X-Branch-Id', (string) $this->gurgaon)
            ->getJson('/api/v1/tenant/exports/billing.invoices/preview')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $preview['total']);

        // Asked for by name, Delhi's bills are still not theirs.
        $this->withHeader('X-Branch-Id', (string) $this->gurgaon)
            ->getJson("/api/v1/tenant/exports/billing.invoices/preview?location_id={$this->delhi}")
            ->assertForbidden();
    }

    public function test_an_organization_role_exports_every_branch(): void
    {
        // Not placed at a branch: their role is the organization-wide one, as head office's is.
        $this->setStaffCapabilities($this->organization, ['exports.billing']);
        $this->signInAsStaff($this->organization);

        $tabs = $this->catalogue()['tabs'];

        $this->assertTrue($tabs[0]['all_branches']);

        $this->assertSame(2, $this->getJson('/api/v1/tenant/exports/billing.invoices/preview')->assertOk()->json('data.total'));
        $this->assertSame(1, $this->getJson("/api/v1/tenant/exports/billing.invoices/preview?location_id={$this->delhi}")->json('data.total'));
    }

    public function test_communication_is_organization_level_only(): void
    {
        $this->grantModule($this->organization, 'communication');

        // Even put on a branch role by hand, it does not reach the log.
        $this->staffAtGurgaon(['exports.communication']);

        $this->assertSame([], $this->catalogue($this->gurgaon)['tabs']);

        $this->signInAsOwner($this->organization);

        $this->assertContains('communication', array_column($this->catalogue()['tabs'], 'key'));
    }

    public function test_a_roles_column_rules_narrow_what_its_holders_take(): void
    {
        $role = $this->staffRoleId($this->organization);

        $this->signInAsOwner($this->organization);

        $this->putJson("/api/v1/tenant/exports/column-rules/{$role}", [
            'hidden' => [
                'patients.register' => ['phone', 'email'],
                // Every column hidden is the export taken away.
                'patients.visits' => ['date', 'time', 'token', 'patient', 'uhid', 'mobile', 'doctor', 'branch', 'type', 'mode', 'status', 'reason'],
            ],
        ])->assertOk();

        $this->staffAtGurgaon(['customers.view', 'customers.records_view', 'exports.patients']);

        $datasets = collect($this->catalogue($this->gurgaon)['tabs'][0]['datasets'])->keyBy('key');

        $this->assertSame(['patients.register'], $datasets->keys()->all());
        $this->assertNotContains('phone', array_column($datasets['patients.register']['columns'], 'key'));
        $this->assertContains('name', array_column($datasets['patients.register']['columns'], 'key'));

        $csv = $this->withHeader('X-Branch-Id', (string) $this->gurgaon)
            ->get('/api/v1/tenant/exports/patients.register/download?format=csv')
            ->assertOk()
            ->streamedContent();

        $this->assertStringNotContainsString('Mobile', $csv);
        $this->assertStringNotContainsString('9811100001', $csv);
        // Their branch's patients only.
        $this->assertStringContainsString('Asha Verma', $csv);
        $this->assertStringNotContainsString('Ravi Kumar', $csv);

        // The Patient Records button follows the same rule.
        $page = $this->withHeader('X-Branch-Id', (string) $this->gurgaon)
            ->get('/api/v1/tenant/patient-records/export?format=csv')
            ->assertOk()
            ->streamedContent();

        $this->assertStringNotContainsString('Mobile', $page);
        $this->assertStringContainsString('UHID', $page);
    }

    public function test_every_format_carries_the_filtered_rows_with_totals(): void
    {
        $this->signInAsOwner($this->organization);

        $excel = $this->get('/api/v1/tenant/exports/billing.invoices/download?format=xlsx')->assertOk();

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($excel->baseResponse->getFile()->getPathname()));
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        $this->assertStringContainsString('Asha Verma', $sheet);
        $this->assertStringContainsString('Ravi Kumar', $sheet);
        // Totals under the money: 500 + 300 billed, 200 paid.
        $this->assertStringContainsString('<v>800</v>', $sheet);
        $this->assertStringContainsString('<v>200</v>', $sheet);

        // A status filter, and a column choice.
        $csv = $this->get('/api/v1/tenant/exports/billing.invoices/download?format=csv&status=partially_paid&columns=number,patient,balance')
            ->assertOk()
            ->streamedContent();

        $lines = array_values(array_filter(explode("\n", ltrim($csv, "\xEF\xBB\xBF"))));

        $this->assertSame(['Invoice no.', 'Patient', 'Balance'], str_getcsv($lines[0]));
        $this->assertCount(2, $lines);
        $this->assertSame('300.00', str_getcsv($lines[1])[2]);

        // Dates outside every bill.
        $this->assertSame(0, $this->getJson('/api/v1/tenant/exports/billing.invoices/preview?from=2026-01-01&to=2026-01-31')->json('data.total'));

        $pdf = $this->get('/api/v1/tenant/exports/patients.visits/download?format=pdf')->assertOk();

        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $this->getJson('/api/v1/tenant/exports/billing.invoices/download?format=doc')->assertUnprocessable();
        $this->getJson('/api/v1/tenant/exports/billing.invoices/preview?status=nonsense')->assertUnprocessable();
        $this->getJson('/api/v1/tenant/exports/pharmacy.sales/preview')->assertNotFound();
    }

    public function test_the_table_pages_sorts_and_exports_only_the_rows_ticked(): void
    {
        $this->signInAsOwner($this->organization);

        $page = $this->getJson('/api/v1/tenant/exports/billing.invoices/preview?per_page=10')->assertOk()->json('data');

        $this->assertSame(['current_page' => 1, 'last_page' => 1, 'per_page' => 10, 'from' => 1, 'to' => 2], $page['meta']);
        $this->assertStringStartsWith('/billing/invoices/', $page['rows'][0]['link']);

        // Cheapest first, then dearest first: Ravi's 300, then Asha's 500.
        $cheap = $this->getJson('/api/v1/tenant/exports/billing.invoices/preview?sort=total&direction=asc')->json('data.rows');
        $dear = $this->getJson('/api/v1/tenant/exports/billing.invoices/preview?sort=total&direction=desc')->json('data.rows');

        $this->assertSame(array_reverse(array_column($cheap, 'id')), array_column($dear, 'id'));

        // Only the row ticked goes into the file.
        $csv = $this->get('/api/v1/tenant/exports/billing.invoices/download?format=csv&ids='.$dear[0]['id'])->assertOk()->streamedContent();

        $this->assertStringContainsString('Asha Verma', $csv);
        $this->assertStringNotContainsString('Ravi Kumar', $csv);

        // The doctor filter, on an export that has one.
        $doctor = $this->onTenant($this->organization, fn () => Doctor::on('organization')->value('id'));

        $this->assertSame(2, $this->getJson("/api/v1/tenant/exports/patients.visits/preview?doctor_id={$doctor}")->json('data.total'));
        $this->assertSame(0, $this->getJson('/api/v1/tenant/exports/patients.visits/preview?doctor_id='.($doctor + 999))->json('data.total'));
    }

    public function test_the_column_rules_are_the_organizations_to_set(): void
    {
        $this->staffAtGurgaon(['exports.billing']);

        $this->withHeader('X-Branch-Id', (string) $this->gurgaon)
            ->getJson('/api/v1/tenant/exports/column-rules')
            ->assertForbidden();

        $this->signInAsOwner($this->organization);

        $data = $this->getJson('/api/v1/tenant/exports/column-rules')->assertOk()->json('data');

        $this->assertContains('billing', array_column($data['tabs'], 'key'));
        $this->assertContains($this->staffRoleId($this->organization), array_column($data['roles'], 'id'));

        $this->putJson('/api/v1/tenant/exports/column-rules/'.$this->staffRoleId($this->organization), [
            'hidden' => ['billing.invoices' => ['no_such_column']],
        ])->assertUnprocessable();
    }
}
