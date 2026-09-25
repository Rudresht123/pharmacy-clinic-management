<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\File;
use App\Models\Tenant\Location;
use App\Models\Tenant\PatientDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TenantTestCase;

/**
 * Patient documents.
 *
 * The point of the module is the SPLIT, so that is what most of this proves:
 * a document's category decides who may read it, and somebody without
 * `documents.view_clinical` must not be able to see a lab report, count it,
 * learn its name, or fetch its bytes by guessing an id.
 *
 * Also asserted: the bytes never land on a public disk; a visit can only be
 * named if it belongs to the patient; removal is soft and keeps its reason.
 */
class PatientDocumentTest extends TenantTestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private int $branch;

    private int $customer;

    private int $appointment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');

        $this->organization = $this->provisionOrganization('D');

        foreach (['appointments', 'documents'] as $module) {
            $this->grantModule($this->organization, $module);
        }

        $this->signInAsOwner($this->organization);

        $this->onTenant($this->organization, function () {
            $this->branch = Location::on('organization')->create([
                'name' => 'Noida',
                'code' => 'D-NOI',
                'type' => Location::CLINIC,
                'is_active' => true,
            ])->id;

            $this->customer = Customer::on('organization')->create([
                'name' => 'Asha Verma',
                'phone' => '9000000001',
            ])->id;

            $doctor = Doctor::on('organization')->create([
                'name' => 'Dr Rao',
                'code' => 'DR-1',
                'is_active' => true,
            ]);

            $this->appointment = Appointment::on('organization')->create([
                'customer_id' => $this->customer,
                'doctor_id' => $doctor->id,
                'location_id' => $this->branch,
                'appointment_date' => '2026-09-07',
                'type' => Appointment::BOOKED,
                'status' => Appointment::STATUS_BOOKED,
            ])->id;
        });
    }

    /** Both halves of the vocabulary, filed against the patient. */
    private function seedDocuments(): array
    {
        return $this->onTenant($this->organization, function () {
            $ids = [];

            foreach ([
                'lab_report' => 'Blood count',
                'insurance' => 'Star Health card',
            ] as $category => $title) {
                $file = File::on('organization')->create([
                    'file_name' => "{$category}.pdf",
                    'file_path' => "patient-documents/x/{$category}.pdf",
                    'disk' => 'local',
                    'mime_type' => 'application/pdf',
                    'file_size' => 1024,
                    'extension' => 'pdf',
                ]);

                $ids[$category] = PatientDocument::on('organization')->create([
                    'customer_id' => $this->customer,
                    'file_id' => $file->id,
                    'location_id' => $this->branch,
                    'category' => $category,
                    'title' => $title,
                ])->id;
            }

            return $ids;
        });
    }

    public function test_a_reader_without_the_clinical_capability_sees_only_administrative_documents(): void
    {
        $ids = $this->seedDocuments();

        $this->setStaffCapabilities($this->organization, ['customers.view', 'documents.view']);
        $this->signInAsStaff($this->organization);

        $body = $this->getJson("/api/v1/tenant/customers/{$this->customer}/documents")
            ->assertOk()
            ->json('data');

        /*
         * One row, and it is the insurance card. Asserting the count as well
         * as the category matters: a clinical document that was filtered out
         * of the display but still counted would tell a pharmacist that the
         * patient has a report, which is itself information they do not hold.
         */
        $this->assertCount(1, $body);
        $this->assertSame('insurance', $body[0]['category']);

        // And the title of the report never reached them.
        $this->assertStringNotContainsString('Blood count', json_encode($body));

        // Nor can they fetch it by naming the id directly.
        $this->getJson("/api/v1/tenant/documents/{$ids['lab_report']}/download")
            ->assertStatus(403);
    }

    public function test_a_clinical_reader_sees_both(): void
    {
        $this->seedDocuments();

        $this->setStaffCapabilities($this->organization, [
            'customers.view', 'documents.view', 'documents.view_clinical',
        ]);
        $this->signInAsStaff($this->organization);

        $categories = collect(
            $this->getJson("/api/v1/tenant/customers/{$this->customer}/documents")
                ->assertOk()
                ->json('data')
        )->pluck('category')->sort()->values()->all();

        $this->assertSame(['insurance', 'lab_report'], $categories);
    }

    public function test_the_category_list_is_narrowed_the_same_way(): void
    {
        $this->setStaffCapabilities($this->organization, ['customers.view', 'documents.view']);
        $this->signInAsStaff($this->organization);

        $keys = collect($this->getJson('/api/v1/tenant/document-categories')->assertOk()->json('data'))
            ->pluck('key')
            ->all();

        // Offering "Lab report" to somebody who could not then read it back
        // would be a form the software refuses to honour.
        $this->assertNotContains('lab_report', $keys);
        $this->assertContains('insurance', $keys);
    }

    public function test_an_upload_is_stored_off_the_public_disk_and_attached_to_the_visit(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $this->setStaffCapabilities($this->organization, [
            'customers.view', 'documents.view', 'documents.view_clinical', 'documents.upload',
        ]);
        $this->signInAsStaff($this->organization);

        $document = $this->postJson("/api/v1/tenant/customers/{$this->customer}/documents", [
            'file' => UploadedFile::fake()->create('report.pdf', 40, 'application/pdf'),
            'category' => 'lab_report',
            'appointment_id' => $this->appointment,
            'title' => 'CBC',
        ])->assertCreated()->json('data');

        $this->assertSame('CBC', $document['title']);
        $this->assertTrue($document['is_clinical']);
        $this->assertSame($this->appointment, $document['appointment_id']);

        // No URL on the resource, and nothing written where a browser could
        // reach it without asking anybody's permission.
        $this->assertArrayNotHasKey('url', $document);
        $this->assertEmpty(Storage::disk('public')->allFiles());

        $stored = Storage::disk('local')->allFiles();
        $this->assertCount(1, $stored);
        $this->assertStringStartsWith(
            "patient-documents/{$this->organization->id}/{$this->customer}/",
            $stored[0],
        );

        // And it shows up as the visit's attachment.
        $this->assertCount(
            1,
            $this->getJson("/api/v1/tenant/appointments/{$this->appointment}/documents")
                ->assertOk()
                ->json('data'),
        );
    }

    /** The endpoint a mobile client leans on hardest: bytes back, as a file. */
    public function test_an_uploaded_document_can_be_read_back(): void
    {
        Storage::fake('local');

        $this->setStaffCapabilities($this->organization, [
            'customers.view', 'documents.view', 'documents.view_clinical', 'documents.upload',
        ]);
        $this->signInAsStaff($this->organization);

        $id = $this->postJson("/api/v1/tenant/customers/{$this->customer}/documents", [
            'file' => UploadedFile::fake()->create('report.pdf', 5, 'application/pdf'),
            'category' => 'lab_report',
        ])->assertCreated()->json('data.id');

        $response = $this->get("/api/v1/tenant/documents/{$id}/download");

        $response->assertOk();

        // As a download, under the name it was uploaded with — not inline,
        // and not under the generated storage name.
        $this->assertStringContainsString(
            'attachment',
            (string) $response->headers->get('content-disposition'),
        );
        $this->assertStringContainsString(
            'report.pdf',
            (string) $response->headers->get('content-disposition'),
        );
    }

    public function test_a_visit_belonging_to_another_patient_is_refused(): void
    {
        Storage::fake('local');

        $other = $this->onTenant($this->organization, fn () => Customer::on('organization')->create([
            'name' => 'Someone Else',
            'phone' => '9000000002',
        ])->id);

        $this->setStaffCapabilities($this->organization, [
            'customers.view', 'documents.view', 'documents.upload',
        ]);
        $this->signInAsStaff($this->organization);

        $this->postJson("/api/v1/tenant/customers/{$other}/documents", [
            'file' => UploadedFile::fake()->create('report.pdf', 10, 'application/pdf'),
            'category' => 'id_proof',
            'appointment_id' => $this->appointment,
        ])->assertStatus(422)->assertJsonPath(
            'errors.appointment_id.0',
            'That visit belongs to a different patient.',
        );
    }

    public function test_uploading_needs_its_own_capability(): void
    {
        Storage::fake('local');

        $this->setStaffCapabilities($this->organization, ['customers.view', 'documents.view']);
        $this->signInAsStaff($this->organization);

        $this->postJson("/api/v1/tenant/customers/{$this->customer}/documents", [
            'file' => UploadedFile::fake()->create('report.pdf', 10, 'application/pdf'),
            'category' => 'id_proof',
        ])->assertStatus(403);
    }

    public function test_removal_is_soft_and_keeps_its_reason(): void
    {
        $ids = $this->seedDocuments();

        $this->setStaffCapabilities($this->organization, [
            'customers.view', 'documents.view', 'documents.view_clinical', 'documents.delete',
        ]);
        $this->signInAsStaff($this->organization);

        $this->deleteJson("/api/v1/tenant/documents/{$ids['lab_report']}", [
            'reason' => 'Filed against the wrong patient',
        ])->assertOk();

        $this->onTenant($this->organization, function () use ($ids) {
            $document = PatientDocument::on('organization')
                ->withTrashed()
                ->findOrFail($ids['lab_report']);

            $this->assertNotNull($document->deleted_at);
            $this->assertSame('Filed against the wrong patient', $document->deletion_reason);
        });

        // Gone from the folder, without the row being gone.
        $this->assertCount(
            1,
            $this->getJson("/api/v1/tenant/customers/{$this->customer}/documents")
                ->assertOk()
                ->json('data'),
        );
    }

    public function test_the_whole_module_disappears_when_the_organization_does_not_have_it(): void
    {
        $bare = $this->provisionOrganization('N');

        $this->signInAsOwner($bare);

        $customer = $this->onTenant($bare, fn () => Customer::on('organization')->create([
            'name' => 'No Module',
            'phone' => '9000000003',
        ])->id);

        // An owner bypasses roles entirely, so this can only be the module.
        $this->getJson("/api/v1/tenant/customers/{$customer}/documents")->assertStatus(403);
    }
}
