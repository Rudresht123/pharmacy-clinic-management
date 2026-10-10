<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\BillableService;
use App\Models\Tenant\BillingSetting;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\InvoiceItem;
use App\Models\Tenant\LabTestCatalog;
use App\Models\Tenant\Location;
use App\Models\Tenant\Medicine;
use App\Models\Tenant\OrganizationModuleSetting;
use App\Models\Tenant\PharmacySale;
use App\Models\Tenant\PharmacyStore;
use App\Models\Tenant\Supplier;
use App\Support\Opd\Weekday;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Each department bills for itself.
 *
 * The rules held here:
 *
 *   - THE OPD bills a visit — the doctor's fee — at the organisation's OPD
 *     timing: at check-in, after the consultation, or only when the desk
 *     does. Once per visit, however often the events repeat, and never left
 *     as a draft that holds the visit open.
 *   - A stored timing that is no longer an OPD timing is read as "after the
 *     consultation", and is not rewritten.
 *   - THE LAB bills each order on its own invoice, at the lab's timing —
 *     `manual` for everybody until they choose — and its bill goes with a
 *     withdrawn order while nothing has been paid.
 *   - THE PHARMACY's money stays on the pharmacy's bill: a dispensing for an
 *     OPD patient never lands on the OPD's invoice.
 *   - Lab billing needs the lab module; reading an old lab bill does not.
 */
class ModuleBillingTest extends TenantTestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-09-07';

    private const NEXT_MONDAY = '2026-09-14';

    private const DOCTOR_PASSWORD = 'doctor-secret-1';

    private Organization $organization;

    private int $branch;

    private int $doctor;

    private int $patient;

    private int $cbc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');

        $this->organization = $this->provisionOrganization('MB');

        foreach (['appointments', 'medicines', 'prescriptions', 'pharmacy', 'laboratory'] as $module) {
            $this->grantModule($this->organization, $module);
        }

        $this->onTenant($this->organization, function () {
            $this->branch = Location::on('organization')->create([
                'name' => 'Gurgaon', 'code' => 'MB-GGN', 'type' => Location::CLINIC, 'is_active' => true,
            ])->id;

            $this->patient = Customer::on('organization')->create([
                'name' => 'Asha Rane', 'phone' => '9810000011', 'is_active' => true,
            ])->id;

            $this->cbc = LabTestCatalog::on('organization')->create([
                'name' => 'CBC', 'code' => 'CBC', 'price' => 300, 'tax_percent' => 0, 'active' => true,
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

        // The doctor charges ₹500 a consultation.
        $this->onTenant($this->organization, fn () => Doctor::on('organization')
            ->whereKey($this->doctor)
            ->update(['default_consultation_fee' => 500]));
    }

    /* ------------------------------------------------------------ helpers */

    private function settings(array $values): void
    {
        $this->onTenant($this->organization, fn () => BillingSetting::current()->forceFill($values)->save());
    }

    /** Booked and arrived. The session is left with the desk. */
    private function arrive(string $date = self::MONDAY, string $slot = '10:00'): int
    {
        $this->signInAsOwner($this->organization);

        $id = (int) $this->postJson('/api/v1/tenant/appointments', [
            'customer_id' => $this->patient,
            'doctor_id' => $this->doctor,
            'location_id' => $this->branch,
            'appointment_date' => $date,
            'type' => Appointment::BOOKED,
            'slot_at' => $slot,
        ])->assertCreated()->json('data.id');

        $this->checkInAtDesk($this->organization, $this->branch, $id);

        return $id;
    }

    /** Called, taken in and written up. The session is left with the doctor. */
    private function consult(int $id, array $writeUp = []): void
    {
        $this->postJson("/api/v1/tenant/appointments/{$id}/call")->assertOk();

        $this->signInAsDoctor();

        $this->postJson("/api/v1/tenant/appointments/{$id}/consultation/start")->assertOk();
        $this->putJson("/api/v1/tenant/appointments/{$id}/consultation", [
            'chief_complaint' => 'Fever for two days',
            ...$writeUp,
        ])->assertOk();
    }

    private function complete(int $id): void
    {
        $this->postJson("/api/v1/tenant/appointments/{$id}/consultation/complete")->assertOk();
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

    /** @return Collection<int, Invoice> */
    private function invoices(int $appointmentId, ?string $kind = null)
    {
        return $this->onTenant($this->organization, fn () => Invoice::on('organization')
            ->with('items')
            ->where('appointment_id', $appointmentId)
            ->when($kind, fn ($q) => $q->where('kind', $kind))
            ->orderBy('id')
            ->get());
    }

    private function visit(int $id): Appointment
    {
        return $this->onTenant($this->organization, fn () => Appointment::on('organization')->findOrFail($id));
    }

    private function orderTests(int $appointmentId): array
    {
        return $this->postJson('/api/v1/tenant/lab-orders', [
            'appointment_id' => $appointmentId,
            'items' => [['test_name' => 'CBC', 'lab_test_catalog_id' => $this->cbc]],
        ])->assertCreated()->json('data');
    }

    private function pay(int $invoiceId, float $amount): void
    {
        $this->signInAsOwner($this->organization);

        $this->postJson("/api/v1/tenant/invoices/{$invoiceId}/payments", [
            'method' => 'cash',
            'amount' => $amount,
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
    }

    /* ---------------------------------------------------------------- OPD */

    public function test_after_the_consultation_the_opd_bills_the_doctors_fee_once(): void
    {
        // Reopening is a same-day act, so the day is the visit's.
        $this->travelTo(self::MONDAY.' 09:00:00');

        $id = $this->arrive();
        $this->consult($id);
        $this->complete($id);

        $bills = $this->invoices($id);

        $this->assertCount(1, $bills);
        $this->assertSame(Invoice::KIND_VISIT, $bills[0]->kind);
        $this->assertSame(Invoice::STATUS_PENDING, $bills[0]->status, 'Drawn ready to pay, never left a draft.');
        $this->assertSame('500.00', $bills[0]->total_amount);
        $this->assertSame(BillingSetting::TRIGGER_CONSULTATION, $bills[0]->trigger);
        $this->assertSame(Appointment::STATUS_AWAITING_PAYMENT, $this->visit($id)->status);

        // Reopened and completed again the same day: still one bill.
        $this->postJson("/api/v1/tenant/appointments/{$id}/consultation/reopen")->assertOk();
        $this->complete($id);

        $this->assertCount(1, $this->invoices($id));
    }

    public function test_paying_the_opd_bill_closes_the_visit(): void
    {
        $id = $this->arrive();
        $this->consult($id);
        $this->complete($id);

        $this->pay($this->invoices($id)[0]->id, 500);

        $this->assertSame(Appointment::STATUS_COMPLETED, $this->visit($id)->status);
    }

    public function test_on_credit_a_registered_patient_may_leave_owing(): void
    {
        $this->settings(['payment_behaviour' => BillingSetting::PAYMENT_CREDIT]);

        $id = $this->arrive();
        $this->consult($id);
        $this->complete($id);

        $this->assertSame(Invoice::STATUS_PENDING, $this->invoices($id)[0]->status);
        $this->assertSame(Appointment::STATUS_COMPLETED, $this->visit($id)->status, 'The visit closes; the debt waits under Outstanding.');
    }

    public function test_check_in_timing_bills_on_arrival_and_not_again(): void
    {
        $this->settings(['default_trigger' => BillingSetting::TRIGGER_CHECKIN]);

        $id = $this->arrive();

        $bills = $this->invoices($id);
        $this->assertCount(1, $bills);
        $this->assertSame(BillingSetting::TRIGGER_CHECKIN, $bills[0]->trigger);

        $this->consult($id);
        $this->complete($id);

        $this->assertCount(1, $this->invoices($id));
    }

    public function test_manual_timing_bills_nothing_until_the_desk_does_and_then_once(): void
    {
        $this->settings(['default_trigger' => BillingSetting::TRIGGER_MANUAL]);

        $id = $this->arrive();
        $this->consult($id);
        $this->complete($id);

        $this->assertCount(0, $this->invoices($id));
        $this->assertSame(Appointment::STATUS_COMPLETED, $this->visit($id)->status);

        $this->signInAsOwner($this->organization);

        $first = $this->postJson('/api/v1/tenant/invoices/bill-visit', ['appointment_id' => $id])
            ->assertCreated()->json('data.id');
        $second = $this->postJson('/api/v1/tenant/invoices/bill-visit', ['appointment_id' => $id])
            ->assertCreated()->json('data.id');

        $this->assertSame($first, $second);
        $this->assertCount(1, $this->invoices($id));
        $this->assertSame(BillingSetting::TRIGGER_MANUAL, $this->invoices($id)[0]->trigger);
    }

    public function test_a_stored_visit_wide_timing_reads_as_after_the_consultation_and_is_not_rewritten(): void
    {
        // Saved when "after medicines" was a timing for the whole visit.
        $this->settings(['default_trigger' => BillingSetting::TRIGGER_PHARMACY]);

        $this->getJson('/api/v1/tenant/billing/settings')
            ->assertOk()
            ->assertJsonPath('data.default_trigger', BillingSetting::TRIGGER_CONSULTATION)
            ->assertJsonPath('data.stored_default_trigger', BillingSetting::TRIGGER_PHARMACY)
            ->assertJsonPath('data.lab_trigger', BillingSetting::TRIGGER_MANUAL);

        $id = $this->arrive();
        $this->consult($id);
        $this->complete($id);

        $this->assertCount(1, $this->invoices($id));
        $this->assertSame(
            BillingSetting::TRIGGER_PHARMACY,
            $this->onTenant($this->organization, fn () => BillingSetting::current()->default_trigger),
        );
    }

    public function test_a_follow_up_inside_the_period_is_charged_the_follow_up_fee(): void
    {
        $this->onTenant($this->organization, fn () => BillableService::on('organization')->create([
            'name' => 'Follow-up', 'kind' => BillableService::KIND_FOLLOW_UP, 'default_price' => 200, 'active' => true,
        ]));

        $first = $this->arrive();
        $this->consult($first, ['follow_up_days' => 7]);
        $this->complete($first);

        $second = $this->arrive(self::NEXT_MONDAY);
        $this->consult($second);
        $this->complete($second);

        $this->assertSame('500.00', $this->invoices($first)[0]->total_amount);

        $followUp = $this->invoices($second)[0];
        $this->assertSame('200.00', $followUp->total_amount);
        $this->assertStringContainsString('Follow-up', $followUp->items[0]->description);
    }

    public function test_a_branchs_own_price_replaces_the_organisations_for_the_same_service(): void
    {
        $this->signInAsOwner($this->organization);

        // A per-visit charge, priced for the organisation and again for this branch.
        $this->postJson('/api/v1/tenant/billable-services', [
            'name' => 'File charge', 'code' => 'FILE', 'kind' => BillableService::KIND_CONSULTATION, 'default_price' => 50,
        ])->assertCreated();
        $this->postJson('/api/v1/tenant/billable-services', [
            'location_id' => $this->branch,
            'name' => 'File charge', 'code' => 'FILE', 'kind' => BillableService::KIND_CONSULTATION, 'default_price' => 70,
        ])->assertCreated();

        $id = $this->arrive();
        $this->consult($id);
        $this->complete($id);

        // ₹500 consultation and the branch's ₹70 — not the organisation's ₹50 as well.
        $this->assertSame('570.00', $this->invoices($id)[0]->total_amount);
    }

    /* ----------------------------------------------------------- pharmacy */

    public function test_a_dispensing_stays_on_the_pharmacys_own_bill(): void
    {
        [$store, $medicine] = $this->pharmacy();

        $id = $this->arrive();
        $this->consult($id);

        $prescription = $this->postJson('/api/v1/tenant/prescriptions', [
            'appointment_id' => $id,
            'items' => [[
                'medicine_id' => $medicine,
                'dose_amount' => 1, 'dose_unit' => 'tablet', 'frequency' => 'bd',
                'duration' => 5, 'duration_unit' => 'days', 'prescribed_quantity' => 10,
            ]],
        ])->assertCreated()->json('data');
        $this->postJson("/api/v1/tenant/prescriptions/{$prescription['id']}/issue")->assertOk();
        $this->complete($id);

        $this->signInAsOwner($this->organization);

        $sale = $this->postJson("/api/v1/tenant/pharmacy-stores/{$store}/sales", [
            'customer_id' => $this->patient,
            'prescription_id' => $prescription['id'],
            'items' => [['medicine_id' => $medicine, 'quantity' => 10]],
            'payments' => [['method' => 'cash', 'amount' => 112]],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data');

        $this->assertSame(PharmacySale::PAID, $sale['payment_status'], 'Paid at the counter, not handed to an invoice.');

        $bills = $this->invoices($id);
        $this->assertCount(1, $bills, 'Only the OPD bill — the pharmacy bills on its own sale.');
        $this->assertSame('500.00', $bills[0]->total_amount);
        $this->assertFalse(
            $bills[0]->items->contains(fn (InvoiceItem $item) => $item->source_type === InvoiceItem::SOURCE_PHARMACY_SALE_ITEM),
        );
    }

    /* ---------------------------------------------------------------- lab */

    public function test_lab_billing_is_manual_by_default_and_bills_an_order_once(): void
    {
        $id = $this->arrive();
        $this->consult($id);
        $order = $this->orderTests($id);

        $this->assertCount(0, $this->invoices($id, Invoice::KIND_LAB));

        $this->signInAsOwner($this->organization);

        $billed = $this->postJson("/api/v1/tenant/lab-orders/{$order['id']}/bill")->assertOk()->json('data');
        $this->postJson("/api/v1/tenant/lab-orders/{$order['id']}/bill")->assertOk();

        $lab = $this->invoices($id, Invoice::KIND_LAB);
        $this->assertCount(1, $lab);
        $this->assertSame('300.00', $lab[0]->total_amount);
        $this->assertSame($order['id'], $lab[0]->lab_order_id);
        $this->assertSame($lab[0]->id, $billed['invoice']['id']);
    }

    public function test_billing_on_order_draws_a_bill_per_order_apart_from_the_opd(): void
    {
        $this->settings(['lab_trigger' => BillingSetting::TRIGGER_LAB_ORDER]);

        $id = $this->arrive();
        $this->consult($id);
        $first = $this->orderTests($id);
        $second = $this->orderTests($id);
        $this->complete($id);

        $lab = $this->invoices($id, Invoice::KIND_LAB);
        $this->assertCount(2, $lab);
        $this->assertEqualsCanonicalizing([$first['id'], $second['id']], $lab->pluck('lab_order_id')->all());

        $opd = $this->invoices($id, Invoice::KIND_VISIT);
        $this->assertCount(1, $opd);
        $this->assertSame('500.00', $opd[0]->total_amount, 'The OPD bill carries no tests.');
    }

    public function test_billing_on_sign_off_waits_for_the_lab(): void
    {
        $this->settings(['lab_trigger' => BillingSetting::TRIGGER_LABORATORY]);

        $id = $this->arrive();
        $this->consult($id);
        $order = $this->orderTests($id);

        $this->assertCount(0, $this->invoices($id, Invoice::KIND_LAB));

        $this->signInAsOwner($this->organization);
        $this->postJson("/api/v1/tenant/lab-orders/{$order['id']}/process")->assertOk();
        $this->putJson("/api/v1/tenant/lab-orders/{$order['id']}/items/{$order['items'][0]['id']}/result", [
            'result_value' => '14.2',
        ])->assertOk();
        $this->postJson("/api/v1/tenant/lab-orders/{$order['id']}/complete")->assertOk();

        $this->assertCount(1, $this->invoices($id, Invoice::KIND_LAB));
    }

    public function test_withdrawing_an_unpaid_order_cancels_its_bill_but_a_paid_one_stands(): void
    {
        $this->settings(['lab_trigger' => BillingSetting::TRIGGER_LAB_ORDER]);

        $id = $this->arrive();
        $this->consult($id);
        $unpaid = $this->orderTests($id);
        $paid = $this->orderTests($id);

        $this->signInAsOwner($this->organization);

        $paidBill = $this->invoices($id, Invoice::KIND_LAB)->firstWhere('lab_order_id', $paid['id']);
        $this->pay($paidBill->id, 300);

        $this->postJson("/api/v1/tenant/lab-orders/{$unpaid['id']}/cancel", ['reason' => 'Patient declined'])->assertOk();
        $this->postJson("/api/v1/tenant/lab-orders/{$paid['id']}/cancel", ['reason' => 'Sample spoiled'])->assertOk();

        $bills = $this->invoices($id, Invoice::KIND_LAB)->keyBy('lab_order_id');

        $this->assertSame(Invoice::STATUS_CANCELLED, $bills[$unpaid['id']]->status);
        $this->assertSame(Invoice::STATUS_PAID, $bills[$paid['id']]->status, 'Money taken stays until somebody refunds it.');
    }

    public function test_lab_billing_needs_the_lab_but_an_old_lab_bill_stays_readable(): void
    {
        $id = $this->arrive();
        $this->consult($id);
        $order = $this->orderTests($id);

        $this->signInAsOwner($this->organization);
        $this->postJson("/api/v1/tenant/lab-orders/{$order['id']}/bill")->assertOk();
        $invoice = $this->invoices($id, Invoice::KIND_LAB)[0];

        // The organisation stops running its lab.
        $this->onTenant($this->organization, fn () => OrganizationModuleSetting::on('organization')
            ->create(['module_key' => 'laboratory', 'is_enabled' => false]));

        $this->postJson("/api/v1/tenant/lab-orders/{$order['id']}/bill")->assertForbidden();

        $this->getJson("/api/v1/tenant/invoices/{$invoice->id}")
            ->assertOk()
            ->assertJsonPath('data.kind', Invoice::KIND_LAB);
    }

    public function test_only_somebody_who_handles_money_sees_an_orders_bill(): void
    {
        $this->settings(['lab_trigger' => BillingSetting::TRIGGER_LAB_ORDER]);

        $id = $this->arrive();
        $this->consult($id);
        $order = $this->orderTests($id);

        // The doctor reads the order, not what the patient owes for it.
        $this->getJson("/api/v1/tenant/lab-orders/{$order['id']}")
            ->assertOk()
            ->assertJsonMissingPath('data.invoice');

        $this->signInAsOwner($this->organization);

        $this->getJson("/api/v1/tenant/lab-orders/{$order['id']}")
            ->assertOk()
            ->assertJsonPath('data.invoice.total_amount', 300);
    }

    /* ------------------------------------------------------------ finance */

    public function test_finance_counts_each_departments_money_once_under_its_own_name(): void
    {
        $this->settings(['lab_trigger' => BillingSetting::TRIGGER_LAB_ORDER]);
        [$store, $medicine] = $this->pharmacy();

        $id = $this->arrive();
        $this->consult($id);
        $this->orderTests($id);
        $this->complete($id);

        $this->pay($this->invoices($id, Invoice::KIND_VISIT)[0]->id, 500);
        $this->pay($this->invoices($id, Invoice::KIND_LAB)[0]->id, 300);

        // A counter sale, paid at the counter.
        $this->postJson("/api/v1/tenant/pharmacy-stores/{$store}/sales", [
            'customer_id' => $this->patient,
            'items' => [['medicine_id' => $medicine, 'quantity' => 10]],
            'payments' => [['method' => 'cash', 'amount' => 112]],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        $data = $this->getJson('/api/v1/tenant/finance/summary?from=2026-01-01&to=2026-12-31')->assertOk()->json('data');
        $modules = collect($data['modules'])->keyBy('key');

        $this->assertEquals(500, $modules['opd']['collected']);
        $this->assertEquals(300, $modules['laboratory']['collected']);
        $this->assertEquals(112, $modules['pharmacy']['collected']);
        $this->assertEquals(912, $data['totals']['collected']);
        $this->assertEquals(912, $data['totals']['billed']);
        $this->assertEquals(0, $data['totals']['outstanding']);
    }

    /* ------------------------------------------------------------ fixtures */

    /** @return array{0: int, 1: int} store, medicine */
    private function pharmacy(): array
    {
        [$store, $medicine, $supplier] = $this->onTenant($this->organization, fn () => [
            PharmacyStore::on('organization')->create([
                'location_id' => $this->branch, 'name' => 'Front Counter', 'code' => 'MB-A',
                'store_type' => PharmacyStore::RETAIL, 'is_active' => true, 'is_default' => true,
            ])->id,
            Medicine::on('organization')->create([
                'generic_name' => 'Amoxicillin', 'brand_name' => 'Mox 500', 'strength' => '500 mg',
                'dosage_form' => 'tablet', 'base_unit' => 'tablet', 'pack_size' => 10,
                // Over the counter, so the same stock serves a dispensing and a plain sale.
                'prescription_required' => false, 'tax_rate' => 12, 'hsn_code' => '3004', 'is_active' => true,
            ])->id,
            Supplier::on('organization')->create(['name' => 'Micro Distributors', 'is_active' => true])->id,
        ]);

        $this->signInAsOwner($this->organization);

        $this->postJson("/api/v1/tenant/pharmacy-stores/{$store}/inwards", [
            'inward_type' => 'purchase',
            'supplier_id' => $supplier,
            'supplier_invoice_no' => 'GRN-'.Str::random(6),
            'received_date' => now()->toDateString(),
            'items' => [[
                'medicine_id' => $medicine, 'batch_number' => 'B-1',
                'expiry_date' => now()->addYear()->toDateString(),
                'quantity' => 10, 'purchase_price' => 70, 'mrp' => 112,
            ]],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        return [$store, $medicine];
    }
}
