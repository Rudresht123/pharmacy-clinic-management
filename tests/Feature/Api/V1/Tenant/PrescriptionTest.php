<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\ActivityLog;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Location;
use App\Models\Tenant\Medicine;
use App\Models\Tenant\MedicineBatch;
use App\Models\Tenant\PharmacyStore;
use App\Models\Tenant\Prescription;
use App\Models\Tenant\StoreMedicine;
use App\Models\Tenant\Supplier;
use App\Support\Opd\Weekday;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Pharmacy Phase 4 — structured prescriptions.
 *
 * What is asserted: only the doctor who saw the patient writes the
 * prescription; a line keeps what it copied from the catalogue; stock is a
 * warning and never a refusal; an issued prescription is cancelled rather
 * than edited or deleted; and the old consultation lines survive the move
 * word for word.
 */
class PrescriptionTest extends TenantTestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-09-07';

    private const MIGRATION = 'database/migrations/organization/2026_09_24_000000_create_prescriptions_tables.php';

    private Organization $organization;

    private int $branch;

    private int $store;

    private int $appointment;

    private ?int $supplierId = null;

    /** @var array<string, int> */
    private array $doctors = [];

    /** @var array<string, int> */
    private array $medicines = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');

        $this->organization = $this->provisionOrganization('R');

        // Before any doctor account exists: a doctor's role is built from what the organization runs.
        foreach (['appointments', 'medicines', 'prescriptions', 'pharmacy'] as $module) {
            $this->grantModule($this->organization, $module);
        }

        $this->signInAsOwner($this->organization);

        $this->onTenant($this->organization, function () {
            $this->branch = Location::on('organization')->create([
                'name' => 'Gurgaon', 'code' => 'R-GGN', 'type' => Location::CLINIC, 'is_active' => true,
            ])->id;

            $this->store = PharmacyStore::on('organization')->create([
                'location_id' => $this->branch,
                'name' => 'OPD counter',
                'code' => 'GGN-OPD',
                'store_type' => PharmacyStore::OPD_COUNTER,
                'is_default' => true,
                'is_active' => true,
            ])->id;

            $medicine = fn (array $values) => Medicine::on('organization')->create([
                'dosage_form' => 'tablet',
                'route' => 'oral',
                'base_unit' => 'tablet',
                'pack_size' => 10,
                'is_active' => true,
                ...$values,
            ])->id;

            $this->medicines = [
                'dolo' => $medicine(['generic_name' => 'Paracetamol', 'brand_name' => 'Dolo 650', 'strength' => '650 mg']),
                'amox' => $medicine([
                    'generic_name' => 'Amoxicillin', 'strength' => '500 mg', 'dosage_form' => 'capsule', 'base_unit' => 'capsule',
                ]),
                'cetirizine' => $medicine(['generic_name' => 'Cetirizine', 'strength' => '10 mg']),
                'ors' => $medicine(['generic_name' => 'ORS', 'dosage_form' => 'powder', 'base_unit' => 'sachet']),
                'syrup' => $medicine([
                    'generic_name' => 'Ambroxol', 'strength' => '15 mg/5 ml', 'dosage_form' => 'syrup',
                    'base_unit' => 'bottle', 'pack_size' => 1,
                ]),
                'retired' => $medicine(['generic_name' => 'Nimesulide', 'strength' => '100 mg', 'is_active' => false]),
            ];
        });

        foreach (['anjali' => 'Dr. Anjali Sharma', 'vikram' => 'Dr. Vikram Rao'] as $key => $name) {
            $id = $this->postJson('/api/v1/tenant/doctors', [
                'name' => $name,
                'is_active' => true,
                'account' => ['email' => "{$key}@clinic.test", 'password' => 'doctor-secret-1'],
            ])->assertCreated()->json('data.id');

            // A sitting at the branch posts the doctor there.
            $this->putJson("/api/v1/tenant/doctors/{$id}/schedules", [
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

            $this->doctors[$key] = $id;
        }

        $customer = $this->onTenant($this->organization, fn () => Customer::on('organization')->create([
            'name' => 'Asha Rane',
            'phone' => '9810000001',
        ])->id);

        $this->appointment = $this->postJson('/api/v1/tenant/appointments', [
            'customer_id' => $customer,
            'doctor_id' => $this->doctors['anjali'],
            'location_id' => $this->branch,
            'appointment_date' => self::MONDAY,
            'type' => 'booked',
            'slot_at' => '10:00',
        ])->assertCreated()->json('data.id');
    }

    private function signInAsDoctor(string $key = 'anjali'): void
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/tenant/auth/login', [
            'subdomain' => $this->organization->subdomain,
            'email' => "{$key}@clinic.test",
            'password' => 'doctor-secret-1',
        ])->assertOk();
    }

    /** Dolo 650: one in the morning and one at night, after food, for five days. */
    private function dolo(array $overrides = []): array
    {
        return array_merge([
            'medicine_id' => $this->medicines['dolo'],
            'dose_amount' => 1,
            'dose_unit' => 'tablet',
            'morning' => 1,
            'night' => 1,
            'duration' => 5,
            'duration_unit' => 'days',
            'food_timing' => 'after_food',
        ], $overrides);
    }

    private function draft(array $items, array $extra = [])
    {
        return $this->postJson('/api/v1/tenant/prescriptions', [
            'appointment_id' => $this->appointment,
            'items' => $items,
            ...$extra,
        ]);
    }

    /** Stock into the store the way it arrives: a goods received note. */
    private function receive(string $medicine, int $packs, string $batch): void
    {
        $this->supplierId ??= $this->onTenant($this->organization, fn () => Supplier::on('organization')->create([
            'name' => 'Micro Distributors',
            'is_active' => true,
        ])->id);

        $this->postJson("/api/v1/tenant/pharmacy-stores/{$this->store}/inwards", [
            'inward_type' => 'purchase',
            'supplier_id' => $this->supplierId,
            'supplier_invoice_no' => 'INV-'.Str::random(6),
            'received_date' => now()->toDateString(),
            'items' => [[
                'medicine_id' => $this->medicines[$medicine],
                'batch_number' => $batch,
                'expiry_date' => now()->addYear()->toDateString(),
                'quantity' => $packs,
                'free_quantity' => 0,
                'purchase_price' => 30,
                'mrp' => 35,
            ]],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
    }

    private function statusOf(int $id): string
    {
        return $this->onTenant($this->organization, fn () => Prescription::on('organization')->findOrFail($id)->status);
    }

    /** A tenant migration runs with `organization` as the default connection. */
    private function asMigrationWould(callable $callback): mixed
    {
        $previous = DB::getDefaultConnection();

        DB::setDefaultConnection('organization');

        try {
            return $callback();
        } finally {
            DB::setDefaultConnection($previous);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Writing one
    |--------------------------------------------------------------------------
    */

    public function test_a_doctor_drafts_a_prescription_for_their_own_visit(): void
    {
        $this->signInAsDoctor();

        $body = $this->draft([
            $this->dolo(),
            ['medicine_name' => 'Chyawanprash', 'instructions' => 'One spoon at night'],
        ])->assertCreated()->json('data');

        $this->assertMatchesRegularExpression('/^RX-\d{5,}$/', $body['prescription_number']);
        $this->assertSame('draft', $body['status']);

        [$dolo, $unlisted] = $body['items'];

        // Copied from the catalogue, with the route defaulted from the medicine.
        $this->assertSame('Dolo 650 (Paracetamol) 650 mg tablet', $dolo['medicine_name']);
        $this->assertSame('oral', $dolo['route']);

        // 1–0–0–1 is twice a day: ten tablets over five days.
        $this->assertSame('bd', $dolo['frequency']);
        $this->assertSame(10, $dolo['prescribed_quantity']);

        $this->assertTrue($unlisted['is_unlisted']);
        $this->assertNull($unlisted['prescribed_quantity']);

        // The screens that read the consultation's old shape read it from here now.
        $lines = $this->getJson("/api/v1/tenant/appointments/{$this->appointment}/consultation")
            ->assertOk()
            ->json('data.prescription');

        $this->assertSame([
            'drug' => 'Dolo 650 (Paracetamol) 650 mg tablet',
            'dose' => '1 tablet',
            'frequency' => '1-0-0-1',
            'duration' => '5 days',
            'notes' => 'After food',
        ], $lines[0]);
        $this->assertSame('Chyawanprash', $lines[1]['drug']);
    }

    /**
     * The capability every doctor login holds says somebody may prescribe,
     * not for whom.
     */
    public function test_only_the_doctor_who_saw_the_patient_writes_it(): void
    {
        $this->signInAsDoctor('vikram');

        $this->draft([$this->dolo()])
            ->assertForbidden()
            ->assertJsonPath('message', 'That visit belongs to another doctor.');

        $this->signInAsDoctor('anjali');
        $id = $this->draft([$this->dolo()])->assertCreated()->json('data.id');

        $this->signInAsDoctor('vikram');
        $this->putJson("/api/v1/tenant/prescriptions/{$id}", ['items' => []])->assertForbidden();

        // The owner reads it, and cannot write one: prescribing is the doctor's.
        $this->signInAsOwner($this->organization);
        $this->getJson("/api/v1/tenant/prescriptions/{$id}")->assertOk();
        $this->putJson("/api/v1/tenant/prescriptions/{$id}", ['items' => []])->assertForbidden();
    }

    public function test_a_visit_has_one_live_prescription_and_a_new_one_after_a_cancellation(): void
    {
        $this->signInAsDoctor();

        $first = $this->draft([$this->dolo()])->assertCreated()->json('data');

        $this->draft([$this->dolo()])
            ->assertStatus(409)
            ->assertJsonPath('message', "This visit already has {$first['prescription_number']}. Edit that one instead.");

        $this->postJson("/api/v1/tenant/prescriptions/{$first['id']}/issue")->assertOk();

        // Cancelling is `prescriptions.cancel`, which a doctor login does not hold.
        $this->postJson("/api/v1/tenant/prescriptions/{$first['id']}/cancel", ['reason' => 'Wrong patient'])
            ->assertForbidden();

        $this->signInAsOwner($this->organization);

        $this->postJson("/api/v1/tenant/prescriptions/{$first['id']}/cancel", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $cancelled = $this->postJson("/api/v1/tenant/prescriptions/{$first['id']}/cancel", ['reason' => 'Wrong patient'])
            ->assertOk()
            ->json('data');

        $this->assertSame('cancelled', $cancelled['status']);
        $this->assertSame('Wrong patient', $cancelled['cancellation_reason']);
        $this->assertSame('cancelled', $cancelled['items'][0]['status']);

        $this->signInAsDoctor();

        $second = $this->draft([$this->dolo()])->assertCreated()->json('data');

        $this->assertNotSame($first['prescription_number'], $second['prescription_number']);
    }

    public function test_issuing_signs_it_off(): void
    {
        $this->signInAsDoctor();

        $id = $this->draft([])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/tenant/prescriptions/{$id}/issue")
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');

        $this->putJson("/api/v1/tenant/prescriptions/{$id}", ['items' => [$this->dolo()]])->assertOk();

        $issued = $this->postJson("/api/v1/tenant/prescriptions/{$id}/issue")->assertOk()->json('data');

        $this->assertSame('issued', $issued['status']);
        $this->assertNotNull($issued['issued_at']);
        $this->assertSame(
            now()->addDays(Prescription::DEFAULT_VALID_DAYS)->toDateString(),
            $issued['valid_until'],
        );

        // Signed off: not edited, not issued twice, not removed.
        $this->putJson("/api/v1/tenant/prescriptions/{$id}", ['items' => []])->assertStatus(409);
        $this->postJson("/api/v1/tenant/prescriptions/{$id}/issue")->assertStatus(409);
        $this->deleteJson("/api/v1/tenant/prescriptions/{$id}", ['reason' => 'Changed my mind'])->assertStatus(409);
    }

    public function test_a_draft_is_removed_with_a_reason(): void
    {
        $this->signInAsDoctor();

        $id = $this->draft([$this->dolo()])->assertCreated()->json('data.id');

        $this->deleteJson("/api/v1/tenant/prescriptions/{$id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->deleteJson("/api/v1/tenant/prescriptions/{$id}", ['reason' => 'Started on the wrong visit'])->assertOk();

        $this->assertNull(
            $this->getJson("/api/v1/tenant/appointments/{$this->appointment}/prescription")
                ->assertOk()
                ->json('data.prescription')
        );

        $this->onTenant($this->organization, function () use ($id) {
            $removed = Prescription::on('organization')->withTrashed()->findOrFail($id);

            $this->assertSame('Started on the wrong visit', $removed->deletion_reason);

            $entry = ActivityLog::on('organization')
                ->where('entity_type', 'Prescription')
                ->where('entity_id', $id)
                ->where('event', 'deleted')
                ->firstOrFail();

            $this->assertSame('Started on the wrong visit', $entry->after['reason']);
        });

        // The visit is free for a new one.
        $this->draft([$this->dolo()])->assertCreated();
    }

    /*
    |--------------------------------------------------------------------------
    | The catalogue behind the lines
    |--------------------------------------------------------------------------
    */

    public function test_a_line_keeps_what_it_copied_when_the_catalogue_changes(): void
    {
        $this->signInAsDoctor();

        $body = $this->draft([$this->dolo()])->assertCreated()->json('data');

        $this->onTenant($this->organization, fn () => Medicine::on('organization')
            ->findOrFail($this->medicines['dolo'])
            ->update(['brand_name' => 'Crocin Advance', 'strength' => '500 mg']));

        // Saved again with the same medicine: an edit, not a new choice, so nothing is re-copied.
        $again = $this->putJson("/api/v1/tenant/prescriptions/{$body['id']}", [
            'items' => [[...$this->dolo(['duration' => 3]), 'id' => $body['items'][0]['id']]],
        ])->assertOk()->json('data.items.0');

        $this->assertSame($body['items'][0]['id'], $again['id']);
        $this->assertSame('Dolo 650 (Paracetamol) 650 mg tablet', $again['medicine_name']);
        $this->assertSame('650 mg', $again['strength']);
        $this->assertSame(6, $again['prescribed_quantity']);
    }

    public function test_a_removed_or_inactive_medicine_cannot_be_chosen(): void
    {
        $this->onTenant($this->organization, fn () => Medicine::on('organization')
            ->findOrFail($this->medicines['cetirizine'])
            ->deleteWithReason('Entered twice'));

        $this->signInAsDoctor();

        $this->draft([$this->dolo(['medicine_id' => $this->medicines['cetirizine']])])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.medicine_id');

        $this->draft([$this->dolo(['medicine_id' => $this->medicines['retired']])])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.medicine_id');

        // Deactivated after it was prescribed: the line already written still saves.
        $body = $this->draft([$this->dolo()])->assertCreated()->json('data');

        $this->onTenant($this->organization, fn () => Medicine::on('organization')
            ->findOrFail($this->medicines['dolo'])
            ->update(['is_active' => false]));

        $this->putJson("/api/v1/tenant/prescriptions/{$body['id']}", [
            'items' => [[...$this->dolo(), 'id' => $body['items'][0]['id']]],
        ])->assertOk();
    }

    public function test_the_quantity_is_worked_out_only_when_it_can_be_counted(): void
    {
        $this->signInAsDoctor();

        $syrup = [
            'medicine_id' => $this->medicines['syrup'],
            'dose_amount' => 5,
            'dose_unit' => 'ml',
            'frequency' => 'tds',
            'duration' => 5,
            'duration_unit' => 'days',
        ];

        // Five ml three times a day is not a count of bottles.
        $this->draft([$syrup])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.prescribed_quantity');

        $line = $this->draft([[...$syrup, 'prescribed_quantity' => 1]])->assertCreated()->json('data.items.0');

        $this->assertSame(1, $line['prescribed_quantity']);
    }

    /*
    |--------------------------------------------------------------------------
    | What is on the shelf
    |--------------------------------------------------------------------------
    */

    public function test_the_doctor_sees_what_is_on_the_shelf_and_prescribes_regardless(): void
    {
        $this->receive('dolo', 5, 'D1');
        $this->receive('amox', 1, 'A1');
        $this->receive('ors', 1, 'O1');

        $this->onTenant($this->organization, function () {
            foreach (['dolo' => 1, 'amox' => 100, 'cetirizine' => 10] as $key => $level) {
                StoreMedicine::on('organization')->updateOrCreate(
                    ['pharmacy_store_id' => $this->store, 'medicine_id' => $this->medicines[$key]],
                    ['reorder_level' => $level, 'is_active' => true],
                );
            }

            // Still on the shelf, no longer dispensable.
            MedicineBatch::on('organization')->where('batch_number', 'O1')->firstOrFail()
                ->forceFill(['status' => MedicineBatch::EXPIRED])->save();
        });

        $this->signInAsDoctor();

        // Out of stock is a warning, never a refusal.
        $this->draft([
            $this->dolo(),
            $this->dolo(['medicine_id' => $this->medicines['cetirizine']]),
        ])->assertCreated();

        $visit = $this->getJson("/api/v1/tenant/appointments/{$this->appointment}/prescription")
            ->assertOk()
            ->json('data');

        $this->assertSame($this->store, $visit['store']['id']);
        $this->assertEqualsCanonicalizing(
            [$this->medicines['dolo'], $this->medicines['cetirizine']],
            array_column($visit['availability'], 'medicine_id'),
        );

        $statuses = collect(
            $this->getJson("/api/v1/tenant/pharmacy-stores/{$this->store}/availability?".http_build_query([
                'medicine_ids' => array_values($this->medicines),
            ]))->assertOk()->json('data')
        )->pluck('status', 'medicine_id');

        $this->assertSame('available', $statuses[$this->medicines['dolo']]);
        $this->assertSame('low_stock', $statuses[$this->medicines['amox']]);
        $this->assertSame('out_of_stock', $statuses[$this->medicines['cetirizine']]);
        $this->assertSame('expired_only', $statuses[$this->medicines['ors']]);
        $this->assertSame('not_stocked', $statuses[$this->medicines['syrup']]);
    }

    /*
    |--------------------------------------------------------------------------
    | The move, and afterwards
    |--------------------------------------------------------------------------
    */

    public function test_the_old_consultation_lines_are_carried_across_word_for_word(): void
    {
        $lines = [
            ['drug' => 'Amlodipine 5mg', 'dose' => '1 tablet', 'frequency' => 'Once a day', 'duration' => '30 days', 'notes' => null],
            ['drug' => 'Pantoprazole 40mg', 'dose' => '1 tablet', 'frequency' => 'Before breakfast', 'duration' => '14 days', 'notes' => 'Empty stomach'],
        ];

        $copied = $this->onTenant($this->organization, function () use ($lines) {
            $visit = Appointment::on('organization')->findOrFail($this->appointment);

            // Written the way the consultation screen used to write it.
            DB::connection('organization')->table('consultations')->insert([
                'appointment_id' => $visit->id,
                'customer_id' => $visit->customer_id,
                'doctor_id' => $visit->doctor_id,
                'chief_complaint' => 'Headache',
                'prescription' => json_encode($lines),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $this->asMigrationWould(fn () => [
                (require base_path(self::MIGRATION))->copyLegacyPrescriptions(),
                // Run again, it finds nothing left to copy.
                (require base_path(self::MIGRATION))->copyLegacyPrescriptions(),
            ]);
        });

        $this->assertSame([1, 0], $copied);

        $this->onTenant($this->organization, function () {
            $prescription = Prescription::on('organization')
                ->with('items')
                ->where('appointment_id', $this->appointment)
                ->firstOrFail();

            $this->assertTrue($prescription->is_legacy);
            $this->assertSame(Prescription::ISSUED, $prescription->status);
            $this->assertMatchesRegularExpression('/^RX-\d{5,}$/', $prescription->prescription_number);
            $this->assertNull($prescription->items[0]->medicine_id);
        });

        $this->signInAsDoctor();

        $this->assertSame(
            $lines,
            $this->getJson("/api/v1/tenant/appointments/{$this->appointment}/consultation")->json('data.prescription'),
        );
    }

    public function test_a_prescription_past_its_date_expires_overnight(): void
    {
        $this->signInAsDoctor();

        $id = $this->draft([$this->dolo()])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/tenant/prescriptions/{$id}/issue", ['valid_until' => now()->toDateString()])->assertOk();

        // Good through the whole of its last day.
        $this->artisan('prescriptions:expire', ['--org' => $this->organization->uuid])->assertSuccessful();
        $this->assertSame(Prescription::ISSUED, $this->statusOf($id));

        $this->travel(1)->days();

        $this->artisan('prescriptions:expire', ['--org' => $this->organization->uuid])->assertSuccessful();
        $this->assertSame(Prescription::EXPIRED, $this->statusOf($id));
    }

    /**
     * `activity_logs` cannot be redacted, so a clinical note or an
     * instruction written into it could never be corrected.
     */
    public function test_clinical_words_stay_out_of_the_audit_log(): void
    {
        $this->signInAsDoctor();

        $this->draft(
            [$this->dolo(['instructions' => 'A private instruction'])],
            ['clinical_notes' => 'A sensitive clinical note'],
        )->assertCreated();

        $this->onTenant($this->organization, function () {
            $logged = ActivityLog::on('organization')
                ->whereIn('entity_type', ['Prescription', 'PrescriptionItem'])
                ->get();

            $this->assertCount(2, $logged->where('event', 'created'));

            $dump = $logged->toJson();

            $this->assertStringNotContainsString('A private instruction', $dump);
            $this->assertStringNotContainsString('A sensitive clinical note', $dump);
        });
    }
}
