<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\BranchMembership;
use App\Models\Tenant\Customer;
use App\Models\Tenant\DocumentTemplate;
use App\Models\Tenant\DocumentTemplateVersion;
use App\Models\Tenant\Location;
use App\Models\Tenant\PatientDocument;
use App\Models\Tenant\Role;
use App\Models\Tenant\User as TenantUser;
use App\Support\Documents\TemplateConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TenantTestCase;

/**
 * Document templates: authority, inheritance, locks and versioning.
 *
 * The two that matter most are the last two. A branch must not be able to
 * change what its organization locked, whatever it posts — and a document
 * printed in September must still read as September's document after the
 * branch changes its letterhead, because that is a medical record somebody may
 * already be holding.
 */
class DocumentTemplateTest extends TenantTestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private int $gurgaon;

    private int $noida;

    /** @var array<string, int> */
    private array $roles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');

        $this->organization = $this->provisionOrganization('DT');

        foreach (['appointments', 'prescriptions', 'medicines', 'pharmacy', 'documents'] as $module) {
            $this->grantModule($this->organization, $module);
        }

        $this->onTenant($this->organization, function () {
            foreach (['Gurgaon' => 'DT-GGN', 'Noida' => 'DT-NOI'] as $name => $code) {
                $id = Location::on('organization')->create([
                    'name' => $name,
                    'code' => $code,
                    'type' => Location::CLINIC,
                    'is_active' => true,
                ])->id;

                $name === 'Gurgaon' ? $this->gurgaon = $id : $this->noida = $id;
            }

            $this->roles = Role::on('organization')->pluck('id', 'slug')->all();
        });

        $this->signInAsOwner($this->organization);
    }

    /** The seeded staff member, put at a branch on a named role. */
    private function beStaffAt(int $locationId, array $capabilities, string $roleSlug = 'staff'): void
    {
        $this->setStaffCapabilities($this->organization, $capabilities);

        $this->onTenant($this->organization, function () use ($locationId) {
            $user = TenantUser::on('organization')->where('email', self::STAFF_EMAIL)->firstOrFail();

            BranchMembership::on('organization')->updateOrCreate(
                ['user_id' => $user->id, 'location_id' => $locationId],
                ['role_id' => $user->role_id ?? ($this->roles['staff'] ?? null), 'is_primary' => true],
            );

            $user->forceFill(['role_id' => null])->save();
        });

        /*
         * setStaffCapabilities writes to whichever role they hold, so it is
         * called again now the membership exists — otherwise the capabilities
         * land on the organization slot that was just cleared.
         */
        $this->setStaffCapabilities($this->organization, $capabilities);
        $this->signInAsStaff($this->organization);
    }

    /** A published template, straight into the database. */
    private function seedTemplate(?int $locationId, string $type = 'prescription', array $overrides = []): DocumentTemplate
    {
        return $this->onTenant($this->organization, function () use ($locationId, $type, $overrides) {
            /*
             * Provisioning now seeds an organisation default for every
             * document type the tenant's modules support (DefaultTemplateSeeder),
             * so a test replacing "the" default has to remove what came free
             * rather than collide with it — the unique index that stops two
             * organisation defaults existing at once is exactly what fires
             * here otherwise.
             */
            DocumentTemplate::on('organization')
                ->where('location_id', $locationId)
                ->where('document_type', $type)
                ->get()
                ->each(fn (DocumentTemplate $existing) => $existing->forceDelete());

            $template = DocumentTemplate::on('organization')->create([
                'location_id' => $locationId,
                'document_type' => $type,
                'name' => $locationId === null ? 'Organisation default' : 'Branch template',
                'status' => DocumentTemplate::ACTIVE,
            ]);

            $config = array_replace_recursive(TemplateConfig::defaultsFor($type), $overrides);

            $version = DocumentTemplateVersion::on('organization')->create([
                'document_template_id' => $template->id,
                'version' => 1,
                'config' => $config,
            ]);

            $version->forceFill(['published_at' => now()])->save();
            $template->forceFill(['active_version_id' => $version->id])->save();

            return $template->fresh();
        });
    }

    public function test_an_organisation_administrator_reaches_every_branch_template(): void
    {
        $this->seedTemplate($this->noida);

        // The owner holds everything, so this is the organisation authority.
        $body = $this->getJson("/api/v1/tenant/document-templates?location_id={$this->noida}")
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($body);
    }

    public function test_a_branch_manager_cannot_read_another_branchs_template(): void
    {
        $other = $this->seedTemplate($this->noida);

        $this->beStaffAt($this->gurgaon, ['documents.template_view', 'documents.template_edit']);

        $this->getJson("/api/v1/tenant/document-templates?location_id={$this->noida}")
            ->assertStatus(403);

        // Nor by naming the id — 404, so it is not confirmed to exist.
        $this->getJson("/api/v1/tenant/document-templates/{$other->id}")->assertStatus(404);
    }

    public function test_a_branch_manager_cannot_write_the_organisation_default(): void
    {
        $default = $this->seedTemplate(null);

        $this->beStaffAt($this->gurgaon, ['documents.template_view', 'documents.template_edit']);

        /*
         * 403, not 404. They CAN see the organisation's default — they print
         * from it — so pretending it does not exist would be a lie they can
         * disprove from the list they were just served. What they may not do
         * is rewrite it.
         */
        $this->putJson("/api/v1/tenant/document-templates/{$default->id}", [
            'name' => 'Hijacked',
            'config' => TemplateConfig::defaultsFor('prescription'),
        ])->assertStatus(403);
    }

    public function test_a_branch_manager_edits_their_own_branchs_template(): void
    {
        $mine = $this->seedTemplate($this->gurgaon);

        $this->beStaffAt($this->gurgaon, ['documents.template_view', 'documents.template_edit']);

        $config = TemplateConfig::defaultsFor('prescription');
        $config['footer']['lines'] = ['Open 9am – 7pm'];

        $this->putJson("/api/v1/tenant/document-templates/{$mine->id}", [
            'name' => 'Gurgaon prescription',
            'config' => $config,
        ])->assertOk();

        $this->onTenant($this->organization, function () use ($mine) {
            $template = DocumentTemplate::on('organization')->with('versions')->find($mine->id);

            /*
             * The edit became V2 and left V1 exactly as it was. V1 is what a
             * prescription already printed was made from.
             */
            $this->assertCount(2, $template->versions);
            $this->assertSame([], $template->versions->last()->config['footer']['lines']);
            $this->assertSame(['Open 9am – 7pm'], $template->versions->first()->config['footer']['lines']);

            // And the published version is still the one in use.
            $this->assertSame($mine->active_version_id, $template->active_version_id);
        });
    }

    public function test_a_locked_field_cannot_be_changed_by_a_branch(): void
    {
        $default = $this->seedTemplate(null);

        // The organisation locks the registered name.
        $this->putJson("/api/v1/tenant/document-templates/{$default->id}/locks", [
            'locked_fields' => ['header.legal_name'],
        ])->assertOk();

        $mine = $this->seedTemplate($this->gurgaon);

        $this->beStaffAt($this->gurgaon, ['documents.template_view', 'documents.template_edit']);

        $config = TemplateConfig::defaultsFor('prescription');
        $config['header']['legal_name'] = 'Something Else Entirely';

        $this->putJson("/api/v1/tenant/document-templates/{$mine->id}", [
            'name' => 'Gurgaon prescription',
            'config' => $config,
        ])->assertStatus(422)->assertJsonValidationErrors('config');

        // An unlocked field on the same save still goes through.
        $allowed = TemplateConfig::defaultsFor('prescription');
        $allowed['footer']['lines'] = ['Parking at the rear'];

        $this->putJson("/api/v1/tenant/document-templates/{$mine->id}", [
            'name' => 'Gurgaon prescription',
            'config' => $allowed,
        ])->assertOk();
    }

    public function test_echoing_a_locked_value_back_unchanged_is_not_a_change(): void
    {
        $default = $this->seedTemplate(null);

        $this->putJson("/api/v1/tenant/document-templates/{$default->id}/locks", [
            'locked_fields' => ['header'],
        ])->assertOk();

        $mine = $this->seedTemplate($this->gurgaon);

        $this->beStaffAt($this->gurgaon, ['documents.template_view', 'documents.template_edit']);

        /*
         * The editor posts the WHOLE config on every save, so a locked header
         * is sent every time. Refusing on what was sent rather than on what
         * changed would make one locked section lock the entire template.
         */
        $this->putJson("/api/v1/tenant/document-templates/{$mine->id}", [
            'name' => 'Gurgaon prescription',
            'config' => TemplateConfig::defaultsFor('prescription'),
        ])->assertOk();
    }

    public function test_only_an_organisation_administrator_may_lock(): void
    {
        $default = $this->seedTemplate(null);

        $this->beStaffAt($this->gurgaon, [
            'documents.template_view', 'documents.template_edit', 'documents.template_publish',
        ]);

        $this->putJson("/api/v1/tenant/document-templates/{$default->id}/locks", [
            'locked_fields' => ['header.legal_name'],
        ])->assertStatus(403);
    }

    public function test_a_template_using_a_placeholder_the_document_cannot_fill_is_refused(): void
    {
        $mine = $this->seedTemplate($this->gurgaon, 'pharmacy_invoice');

        $config = TemplateConfig::defaultsFor('pharmacy_invoice');
        $config['footer']['terms'] = 'Diagnosis was {{diagnosis}}';

        $this->putJson("/api/v1/tenant/document-templates/{$mine->id}", [
            'name' => 'Gurgaon invoice',
            'config' => $config,
        ])->assertStatus(422)->assertJsonValidationErrors('config');
    }

    public function test_a_misspelt_placeholder_is_refused(): void
    {
        $mine = $this->seedTemplate($this->gurgaon);

        $config = TemplateConfig::defaultsFor('prescription');
        $config['body']['intro'] = 'Dear {{patinet_name}}';

        $this->putJson("/api/v1/tenant/document-templates/{$mine->id}", [
            'name' => 'Gurgaon prescription',
            'config' => $config,
        ])->assertStatus(422);
    }

    public function test_generating_falls_back_to_the_organisation_default(): void
    {
        Storage::fake('local');

        $this->seedTemplate(null, 'patient_registration');

        $patient = $this->onTenant($this->organization, fn () => Customer::on('organization')->create([
            'name' => 'Asha Verma',
            'phone' => '9000000010',
        ])->id);

        $document = $this->withHeader('X-Branch-Id', (string) $this->gurgaon)
            ->postJson('/api/v1/tenant/documents/generate', [
                'document_type' => 'patient_registration',
                'subject_id' => $patient,
            ])->assertCreated()->json('data');

        $this->assertSame('generated', $document['source']);
        $this->assertSame('registration', $document['category']);

        // Off the public disk, like every other patient document.
        $this->assertNotEmpty(Storage::disk('local')->allFiles());
    }

    public function test_a_branch_override_wins_over_the_organisation_default(): void
    {
        Storage::fake('local');

        $this->seedTemplate(null, 'patient_registration');
        $branch = $this->seedTemplate($this->gurgaon, 'patient_registration');

        $patient = $this->onTenant($this->organization, fn () => Customer::on('organization')->create([
            'name' => 'Asha Verma',
            'phone' => '9000000011',
        ])->id);

        $document = $this->withHeader('X-Branch-Id', (string) $this->gurgaon)
            ->postJson('/api/v1/tenant/documents/generate', [
                'document_type' => 'patient_registration',
                'subject_id' => $patient,
            ])->assertCreated()->json('data');

        $this->onTenant($this->organization, function () use ($document, $branch) {
            $row = PatientDocument::on('organization')->find($document['id']);

            $this->assertSame($branch->id, (int) $row->document_template_id);
        });
    }

    public function test_an_old_document_keeps_the_version_it_was_printed_from(): void
    {
        Storage::fake('local');

        $template = $this->seedTemplate($this->gurgaon, 'patient_registration');
        $firstVersion = $template->active_version_id;

        $patient = $this->onTenant($this->organization, fn () => Customer::on('organization')->create([
            'name' => 'Asha Verma',
            'phone' => '9000000012',
        ])->id);

        $old = $this->withHeader('X-Branch-Id', (string) $this->gurgaon)
            ->postJson('/api/v1/tenant/documents/generate', [
                'document_type' => 'patient_registration',
                'subject_id' => $patient,
            ])->assertCreated()->json('data');

        // The branch changes its letterhead and puts it into use.
        $config = TemplateConfig::defaultsFor('patient_registration');
        $config['footer']['lines'] = ['A completely new footer'];

        $this->putJson("/api/v1/tenant/document-templates/{$template->id}", [
            'name' => 'Gurgaon registration',
            'config' => $config,
        ])->assertOk();

        $this->postJson("/api/v1/tenant/document-templates/{$template->id}/publish")->assertOk();

        $new = $this->withHeader('X-Branch-Id', (string) $this->gurgaon)
            ->postJson('/api/v1/tenant/documents/generate', [
                'document_type' => 'patient_registration',
                'subject_id' => $patient,
            ])->assertCreated()->json('data');

        $this->onTenant($this->organization, function () use ($old, $new, $firstVersion, $template) {
            $before = PatientDocument::on('organization')->find($old['id']);
            $after = PatientDocument::on('organization')->find($new['id']);

            /*
             * The whole point of the module: the September document still
             * points at September's version, and the new one at the new one.
             */
            $this->assertSame($firstVersion, (int) $before->document_template_version_id);
            $this->assertNotSame(
                (int) $before->document_template_version_id,
                (int) $after->document_template_version_id,
            );

            $this->assertSame(
                (int) DocumentTemplate::on('organization')->find($template->id)->active_version_id,
                (int) $after->document_template_version_id,
            );

            // And V1's config was never rewritten.
            $v1 = DocumentTemplateVersion::on('organization')->find($firstVersion);
            $this->assertSame([], $v1->config['footer']['lines']);
        });
    }

    public function test_a_draft_template_cannot_print_anything(): void
    {
        Storage::fake('local');

        $this->onTenant($this->organization, function () {
            // Provisioning already seeded a default for this type — see the
            // note in seedTemplate().
            DocumentTemplate::on('organization')
                ->whereNull('location_id')
                ->where('document_type', 'patient_registration')
                ->get()
                ->each(fn (DocumentTemplate $existing) => $existing->forceDelete());

            $template = DocumentTemplate::on('organization')->create([
                'location_id' => null,
                'document_type' => 'patient_registration',
                'name' => 'Not published',
                'status' => DocumentTemplate::DRAFT,
            ]);

            DocumentTemplateVersion::on('organization')->create([
                'document_template_id' => $template->id,
                'version' => 1,
                'config' => TemplateConfig::defaultsFor('patient_registration'),
            ]);
        });

        $patient = $this->onTenant($this->organization, fn () => Customer::on('organization')->create([
            'name' => 'Asha Verma',
            'phone' => '9000000013',
        ])->id);

        /*
         * Refused rather than falling back to "the latest template". A draft
         * is not a document anybody approved.
         */
        $this->withHeader('X-Branch-Id', (string) $this->gurgaon)
            ->postJson('/api/v1/tenant/documents/generate', [
                'document_type' => 'patient_registration',
                'subject_id' => $patient,
            ])->assertStatus(422);
    }

    public function test_printing_needs_its_own_capability(): void
    {
        Storage::fake('local');

        $this->seedTemplate(null, 'patient_registration');

        $patient = $this->onTenant($this->organization, fn () => Customer::on('organization')->create([
            'name' => 'Asha Verma',
            'phone' => '9000000014',
        ])->id);

        // Everything about reading documents, and nothing about making one.
        $this->beStaffAt($this->gurgaon, ['customers.view', 'documents.view', 'documents.view_clinical']);

        $this->postJson('/api/v1/tenant/documents/generate', [
            'document_type' => 'patient_registration',
            'subject_id' => $patient,
        ])->assertStatus(403);
    }

    public function test_a_preview_renders_a_pdf_without_storing_anything(): void
    {
        Storage::fake('local');

        $response = $this->post('/api/v1/tenant/document-templates/preview', [
            'document_type' => 'prescription',
            'config' => TemplateConfig::defaultsFor('prescription'),
        ]);

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF-', $response->getContent());

        // A preview is rendered and thrown away.
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    public function test_a_document_type_whose_module_is_absent_is_not_offered(): void
    {
        $bare = $this->provisionOrganization('NB');
        $this->grantModule($bare, 'documents');

        $this->signInAsOwner($bare);

        $keys = collect($this->getJson('/api/v1/tenant/document-types')->assertOk()->json('data'))
            ->pluck('key')
            ->all();

        // customers is core, so registration is there; pharmacy is not sold.
        $this->assertContains('patient_registration', $keys);
        $this->assertNotContains('pharmacy_invoice', $keys);
        $this->assertNotContains('prescription', $keys);
    }
}
