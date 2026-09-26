<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Location;
use App\Models\Tenant\Medicine;
use App\Models\Tenant\MedicineBatch;
use App\Models\Tenant\PharmacySale;
use App\Models\Tenant\PharmacyStore;
use App\Models\Tenant\Prescription;
use App\Models\Tenant\PrescriptionItem;
use App\Models\Tenant\Supplier;
use App\Support\Opd\Weekday;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Where the clinic meets the counter.
 *
 * The rules this is here to hold:
 *
 *   - PRESCRIBING MOVES NO STOCK. Only handing medicines over does.
 *   - a dispensing credits the prescribed lines it fulfils, which is what
 *     `dispensed_quantity` was for and what nothing ever wrote.
 *   - a part-dispensed prescription keeps the visit open. Somebody coming
 *     back for the rest has not finished.
 *   - money owed keeps it open too, and paying closes it.
 *   - cancelling the bill walks all of that back, including the visit.
 */
class DispensingWorkflowTest extends TenantTestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-09-07';

    private const DOCTOR_PASSWORD = 'doctor-secret-1';

    private Organization $organization;

    private int $branch;

    private int $store;

    private int $medicine;

    private int $supplier;

    private int $doctor;

    private int $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');

        $this->organization = $this->provisionOrganization('DW');

        foreach (['medicines', 'pharmacy', 'appointments', 'prescriptions'] as $module) {
            $this->grantModule($this->organization, $module);
        }

        $this->onTenant($this->organization, function () {
            $this->branch = Location::on('organization')->create([
                'name' => 'Gurgaon', 'code' => 'DW-GGN', 'type' => Location::CLINIC, 'is_active' => true,
            ])->id;

            $this->store = PharmacyStore::on('organization')->create([
                'location_id' => $this->branch,
                'name' => 'Front Counter',
                'code' => 'DW-A',
                'store_type' => PharmacyStore::RETAIL,
                'is_active' => true,
                'is_default' => true,
            ])->id;

            $this->medicine = Medicine::on('organization')->create([
                'generic_name' => 'Amoxicillin',
                'brand_name' => 'Mox 500',
                'strength' => '500 mg',
                'dosage_form' => 'tablet',
                'base_unit' => 'tablet',
                'pack_size' => 10,
                'prescription_required' => true,
                'tax_rate' => 12,
                'hsn_code' => '3004',
                'is_active' => true,
            ])->id;

            $this->supplier = Supplier::on('organization')->create([
                'name' => 'Micro Distributors', 'is_active' => true,
            ])->id;

            $this->patient = Customer::on('organization')->create([
                'name' => 'Asha Rane', 'phone' => '9810000002', 'is_active' => true,
            ])->id;
        });

        $this->placeStaffAt($this->organization, $this->branch);
        $this->signInAsOwner($this->organization);

        $doctor = $this->postJson('/api/v1/tenant/doctors', [
            'name' => 'Dr. Anjali Sharma',
            'is_active' => true,
            'account' => ['email' => 'anjali@clinic.test', 'password' => self::DOCTOR_PASSWORD],
        ])->assertCreated()->json('data');

        $this->doctor = (int) $doctor['id'];

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

    /** One batch on the shelf, received as an invoice reads it (in packs). */
    private function stock(int $packs = 10): int
    {
        $this->postJson("/api/v1/tenant/pharmacy-stores/{$this->store}/inwards", [
            'inward_type' => 'purchase',
            'supplier_id' => $this->supplier,
            'supplier_invoice_no' => 'GRN-'.Str::random(6),
            'received_date' => now()->toDateString(),
            'items' => [[
                'medicine_id' => $this->medicine,
                'batch_number' => 'B-1',
                'expiry_date' => now()->addYear()->toDateString(),
                'quantity' => $packs,
                'purchase_price' => 70,
                'mrp' => 112,
            ]],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        return (int) $this->onTenant($this->organization, fn () => MedicineBatch::on('organization')
            ->where('pharmacy_store_id', $this->store)
            ->where('batch_number', 'B-1')
            ->value('id'));
    }

    private function onHand(int $batchId): int
    {
        return (int) $this->onTenant(
            $this->organization,
            fn () => MedicineBatch::on('organization')->whereKey($batchId)->value('quantity_available'),
        );
    }

    private function visit(int $appointmentId): Appointment
    {
        return $this->onTenant(
            $this->organization,
            fn () => Appointment::on('organization')->findOrFail($appointmentId),
        );
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

    /**
     * A visit taken all the way to a completed consultation with a
     * prescription for 20 tablets against it.
     *
     * @return array{0: int, 1: array<string, mixed>} appointment id, prescription
     */
    private function visitWithPrescription(int $quantity = 20): array
    {
        $appointmentId = (int) $this->postJson('/api/v1/tenant/appointments', [
            'customer_id' => $this->patient,
            'doctor_id' => $this->doctor,
            'location_id' => $this->branch,
            'appointment_date' => self::MONDAY,
            'type' => Appointment::BOOKED,
            'slot_at' => '10:00',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/tenant/appointments/{$appointmentId}/check-in")->assertOk();
        $this->postJson("/api/v1/tenant/appointments/{$appointmentId}/call")->assertOk();

        $this->signInAsDoctor();

        $this->postJson("/api/v1/tenant/appointments/{$appointmentId}/consultation/start")->assertOk();

        $this->putJson("/api/v1/tenant/appointments/{$appointmentId}/consultation", [
            'chief_complaint' => 'Sore throat',
        ])->assertOk();

        $prescription = $this->postJson('/api/v1/tenant/prescriptions', [
            'appointment_id' => $appointmentId,
            'items' => [[
                'medicine_id' => $this->medicine,
                'dose_amount' => 1,
                'dose_unit' => 'tablet',
                'frequency' => 'bd',
                'duration' => 10,
                'duration_unit' => 'days',
                'prescribed_quantity' => $quantity,
            ]],
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/tenant/prescriptions/{$prescription['id']}/issue")->assertOk();

        $this->postJson("/api/v1/tenant/appointments/{$appointmentId}/consultation/complete")->assertOk();

        // Back to the counter's own login for the dispensing itself.
        $this->signInAsOwner($this->organization);

        return [$appointmentId, $prescription];
    }

    /** Dispense some or all of the prescription, and say what was paid. */
    private function dispense(array $prescription, int $quantity, ?float $paid = null): array
    {
        $payload = [
            'customer_id' => $this->patient,
            'prescription_id' => $prescription['id'],
            'items' => [[
                'medicine_id' => $this->medicine,
                'quantity' => $quantity,
            ]],
        ];

        if ($paid !== null) {
            $payload['payments'] = [['method' => 'cash', 'amount' => $paid]];
        }

        $response = $this->postJson(
            "/api/v1/tenant/pharmacy-stores/{$this->store}/sales",
            $payload,
            ['Idempotency-Key' => (string) Str::uuid()],
        );

        // The server's own sentence when it refuses, rather than a bare
        // status code — a dispensing has a dozen ways to be turned down and
        // "expected 201, got 409" names none of them.
        $this->assertSame(201, $response->status(), (string) $response->json('message'));

        return $response->json('data');
    }

    /*
    |--------------------------------------------------------------------------
    | The rule the brief is most explicit about
    |--------------------------------------------------------------------------
    */

    /**
     * WRITING A PRESCRIPTION MOVES NO STOCK.
     *
     * Only handing the medicines over does. A clinic that deducted on
     * prescribing would show an empty shelf for every patient who took their
     * script to the chemist down the road.
     */
    public function test_prescribing_does_not_deduct_stock(): void
    {
        $batch = $this->stock(packs: 10);

        $this->assertSame(100, $this->onHand($batch));

        $this->visitWithPrescription(quantity: 20);

        $this->assertSame(100, $this->onHand($batch));
    }

    /*
    |--------------------------------------------------------------------------
    | 13 · Pharmacy completion allows visit completion
    |--------------------------------------------------------------------------
    */

    /** Fully dispensed, fully paid: nothing is left, so the visit is over. */
    public function test_dispensing_in_full_and_settling_completes_the_visit(): void
    {
        $batch = $this->stock(packs: 10);

        [$appointmentId, $prescription] = $this->visitWithPrescription(quantity: 20);

        $this->assertSame(Appointment::STATUS_AWAITING_PHARMACY, $this->visit($appointmentId)->status);

        $sale = $this->dispense($prescription, 20, paid: 224.0);

        // The stock moved now, and only now.
        $this->assertSame(80, $this->onHand($batch));

        // The prescription knows it was filled.
        $this->assertSame(
            Prescription::DISPENSED,
            $this->onTenant($this->organization, fn () => Prescription::on('organization')
                ->whereKey($prescription['id'])->value('status')),
        );

        // And the line it was filled against was credited.
        $this->assertSame(
            20,
            (int) $this->onTenant($this->organization, fn () => PrescriptionItem::on('organization')
                ->where('prescription_id', $prescription['id'])->value('dispensed_quantity')),
        );

        $visit = $this->visit($appointmentId);

        $this->assertSame(Appointment::STATUS_COMPLETED, $visit->status);
        $this->assertSame(Appointment::NEXT_NONE, $visit->next_action);
        $this->assertNotNull($visit->visit_completed_at);

        // The bill found its visit without anybody telling it which.
        $this->assertSame($appointmentId, (int) $this->onTenant(
            $this->organization,
            fn () => PharmacySale::on('organization')->whereKey($sale['id'])->value('appointment_id'),
        ));
    }

    /** Part dispensed: they are coming back, so the visit stays open. */
    public function test_a_part_dispensed_prescription_keeps_the_visit_awaiting_pharmacy(): void
    {
        $this->stock(packs: 10);

        [$appointmentId, $prescription] = $this->visitWithPrescription(quantity: 20);

        $this->dispense($prescription, 8, paid: 89.6);

        $this->assertSame(
            Prescription::PARTIALLY_DISPENSED,
            $this->onTenant($this->organization, fn () => Prescription::on('organization')
                ->whereKey($prescription['id'])->value('status')),
        );

        $this->assertSame(
            Appointment::STATUS_AWAITING_PHARMACY,
            $this->visit($appointmentId)->status,
        );

        // The rest, later. That is what closes it.
        $this->dispense($prescription, 12, paid: 134.4);

        $this->assertSame(Appointment::STATUS_COMPLETED, $this->visit($appointmentId)->status);
    }

    /*
    |--------------------------------------------------------------------------
    | 12 & 15 · Payment
    |--------------------------------------------------------------------------
    */

    /**
     * 12 · Everything handed over and nothing paid: the till has them next.
     *
     * The medicines are gone from the shelf, so the pharmacy is finished —
     * and the visit is not, because the bill stands unpaid.
     */
    public function test_an_unpaid_dispensing_leaves_the_visit_awaiting_payment(): void
    {
        $this->allowCredit();

        $this->stock(packs: 10);

        [$appointmentId, $prescription] = $this->visitWithPrescription(quantity: 20);

        $this->dispense($prescription, 20);

        $visit = $this->visit($appointmentId);

        $this->assertSame(Appointment::STATUS_AWAITING_PAYMENT, $visit->status);
        $this->assertSame(Appointment::NEXT_BILLING, $visit->next_action);
    }

    /*
    |--------------------------------------------------------------------------
    | Undoing it
    |--------------------------------------------------------------------------
    */

    /**
     * Cancelling the bill walks the whole thing back — including the visit.
     *
     * Without this the prescription would still read `dispensed` while the
     * patient has nothing, and the visit would stay closed on the strength
     * of a bill that no longer exists.
     */
    public function test_cancelling_a_dispensing_reopens_the_pharmacy_step(): void
    {
        $batch = $this->stock(packs: 10);

        [$appointmentId, $prescription] = $this->visitWithPrescription(quantity: 20);

        $sale = $this->dispense($prescription, 20, paid: 224.0);

        $this->assertSame(Appointment::STATUS_COMPLETED, $this->visit($appointmentId)->status);

        $this->postJson("/api/v1/tenant/sales/{$sale['id']}/cancel", [
            'reason' => 'Wrong patient',
        ])->assertOk();

        // Stock back on the shelf…
        $this->assertSame(100, $this->onHand($batch));

        // …the prescription back to unfilled…
        $this->assertSame(
            Prescription::ISSUED,
            $this->onTenant($this->organization, fn () => Prescription::on('organization')
                ->whereKey($prescription['id'])->value('status')),
        );

        // …and the visit waiting on the pharmacy again.
        $this->assertSame(
            Appointment::STATUS_AWAITING_PHARMACY,
            $this->visit($appointmentId)->status,
        );
    }

    /** A prescription the doctor has not signed cannot be dispensed against. */
    public function test_a_draft_prescription_cannot_be_dispensed(): void
    {
        $this->stock(packs: 10);

        $appointmentId = (int) $this->postJson('/api/v1/tenant/appointments', [
            'customer_id' => $this->patient,
            'doctor_id' => $this->doctor,
            'location_id' => $this->branch,
            'appointment_date' => self::MONDAY,
            'type' => Appointment::BOOKED,
            'slot_at' => '10:15',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/tenant/appointments/{$appointmentId}/check-in")->assertOk();
        $this->postJson("/api/v1/tenant/appointments/{$appointmentId}/call")->assertOk();

        $this->signInAsDoctor();
        $this->postJson("/api/v1/tenant/appointments/{$appointmentId}/consultation/start")->assertOk();

        $draft = $this->postJson('/api/v1/tenant/prescriptions', [
            'appointment_id' => $appointmentId,
            'items' => [[
                'medicine_id' => $this->medicine,
                'dose_amount' => 1,
                'dose_unit' => 'tablet',
                'frequency' => 'bd',
                'duration' => 10,
                'duration_unit' => 'days',
                'prescribed_quantity' => 20,
            ]],
        ])->assertCreated()->json('data');

        $this->signInAsOwner($this->organization);

        $this->postJson("/api/v1/tenant/pharmacy-stores/{$this->store}/sales", [
            'customer_id' => $this->patient,
            'prescription_id' => $draft['id'],
            'items' => [['medicine_id' => $this->medicine, 'quantity' => 20]],
            'payments' => [['method' => 'cash', 'amount' => 224.0]],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(409);
    }

    /** Handing over more than was prescribed is refused, with the numbers. */
    public function test_dispensing_more_than_was_prescribed_is_refused(): void
    {
        $this->stock(packs: 10);

        [, $prescription] = $this->visitWithPrescription(quantity: 20);

        $response = $this->postJson("/api/v1/tenant/pharmacy-stores/{$this->store}/sales", [
            'customer_id' => $this->patient,
            'prescription_id' => $prescription['id'],
            'items' => [['medicine_id' => $this->medicine, 'quantity' => 30]],
            'payments' => [['method' => 'cash', 'amount' => 336.0]],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(409);

        $this->assertStringContainsString('20 prescribed', $response->json('message'));
    }

    /**
     * Credit sales, so a bill can be left unpaid at all.
     *
     * Off by default, and the "awaiting payment" test is meaningless without
     * it — the service refuses an unsettled bill outright otherwise.
     */
    private function allowCredit(): void
    {
        $this->onTenant($this->organization, function () {
            $settings = \App\Models\Tenant\PharmacySetting::current();

            $settings->forceFill(['credit_sales_enabled' => true])->save();
        });
    }
}
