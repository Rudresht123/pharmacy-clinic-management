<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\Location;
use App\Support\Documents\TemplateConfig;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TenantTestCase;

/**
 * Every row of the Settings menu can be given on a branch's role.
 *
 * Every staff role is a branch's own, so a tick a branch role could not hold was
 * a tick nobody but the owner could ever have. What is asserted: each one is
 * offered and saves, the rest of the organization-wide ticks stay refused, and
 * holding one tick really opens its screen rather than ending in a 403 the Roles
 * page has nothing to offer for.
 */
class SettingsTicksOnRolesTest extends TenantTestCase
{
    use RefreshDatabase;

    private const SETTINGS_TICKS = [
        'settings.manage',
        'settings.departments',
        'settings.audit',
        'settings.subscription',
        'documents.template_view',
        'documents.template_edit',
        'documents.template_publish',
        'documents.template_org',
        'billing.manage_settings',
        'billing.manage_services',
        'appointments.settings',
        'queue.settings',
        'queue.displays',
        'pharmacy.settings_view',
        'pharmacy.stores',
    ];

    private Organization $organization;

    private int $here;

    private int $elsewhere;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');

        $this->organization = $this->provisionOrganization('SET');

        foreach (['appointments', 'queue', 'medicines', 'pharmacy', 'documents'] as $module) {
            $this->grantModule($this->organization, $module);
        }

        [$this->here, $this->elsewhere] = $this->onTenant($this->organization, fn () => array_map(
            fn (string $name) => Location::on('organization')->create([
                'name' => $name, 'code' => 'SET-'.$name, 'type' => Location::CLINIC, 'is_active' => true,
            ])->id,
            ['MGR', 'DEL'],
        ));

        $this->placeStaffAt($this->organization, $this->here);
    }

    /** @param list<string> $ticks */
    private function holding(array $ticks): void
    {
        $this->setStaffCapabilities($this->organization, $ticks);
        $this->signInAsStaff($this->organization);
    }

    public function test_a_branch_role_is_offered_every_settings_tick_and_no_other_organization_one(): void
    {
        $this->holding(['people.roles', 'people.roles_view']);

        $offered = collect($this->getJson('/api/v1/tenant/roles/grantable?scope=branch')->assertOk()->json('data.modules'))
            ->flatMap(fn (array $module) => $module['capabilities']);

        $marked = $offered->where('settings', true)->pluck('key')->sort()->values()->all();

        $expected = self::SETTINGS_TICKS;
        sort($expected);

        $this->assertSame($expected, $marked);

        foreach (['branches.create', 'people.across_branches', 'appointments.doctors', 'communication.manage'] as $tick) {
            $this->assertNotContains($tick, $offered->pluck('key')->all());
        }
    }

    public function test_a_branch_role_saves_them_and_still_refuses_the_rest(): void
    {
        $this->holding(['people.roles', 'people.roles_view']);

        $this->postJson('/api/v1/tenant/roles', [
            'name' => 'Settings admin',
            'capabilities' => self::SETTINGS_TICKS,
        ])->assertCreated();

        foreach (['branches.create', 'people.across_branches'] as $tick) {
            $this->postJson('/api/v1/tenant/roles', [
                'name' => 'Too far '.$tick,
                'capabilities' => [$tick],
            ])->assertStatus(422)->assertJsonValidationErrors('capabilities.0');
        }
    }

    /** No starter role is handed them: a role holds what somebody ticked. */
    public function test_they_are_not_in_the_starter_templates(): void
    {
        $this->holding(['people.roles', 'people.roles_view']);

        $inTemplates = collect($this->getJson('/api/v1/tenant/roles/templates')->assertOk()->json('data'))
            ->flatMap(fn (array $template) => $template['capabilities'])
            ->unique()
            ->all();

        foreach (['settings.manage', 'settings.subscription', 'documents.template_org', 'billing.manage_settings'] as $tick) {
            $this->assertNotContains($tick, $inTemplates);
        }
    }

    /**
     * Each tick alone, the reads its screen makes and the save behind it. A save
     * sent empty answers 422 from validation, which means the tick let it through.
     */
    public function test_one_tick_opens_its_own_screen(): void
    {
        $screens = [
            'settings.manage' => [['get', 'settings/fields/location'], ['put', 'settings/fields/location']],
            'settings.departments' => [['get', 'departments'], ['post', 'departments']],
            'settings.audit' => [['get', 'history'], ['get', 'history-filters']],
            'settings.subscription' => [['get', 'subscription']],
            'billing.manage_settings' => [['get', 'billing/settings'], ['get', 'billing/numbering'], ['put', 'billing/settings']],
            'billing.manage_services' => [['get', 'billable-services'], ['post', 'billable-services']],
            'appointments.settings' => [['get', 'appointment-settings'], ['put', 'appointment-settings']],
            'pharmacy.settings_view' => [['get', 'pharmacy/settings']],
            'pharmacy.stores' => [['get', 'pharmacy/settings'], ['put', 'pharmacy/settings']],
            'documents.template_org' => [['get', 'document-rules']],
            'documents.template_view' => [['get', 'document-templates'], ['get', 'document-types'], ['get', "locations/{$this->here}"]],
        ];

        foreach ($screens as $tick => $requests) {
            $this->holding([$tick]);

            foreach ($requests as [$verb, $path]) {
                $response = $this->json(strtoupper($verb), "/api/v1/tenant/{$path}");

                $verb === 'get'
                    ? $this->assertSame(200, $response->status(), "{$tick} should open GET {$path}: ".$response->getContent())
                    : $this->assertNotContains($response->status(), [401, 403, 404], "{$tick} should reach {$verb} {$path}");
            }
        }
    }

    /** The refusal that started this: the View tick alone, on a Clinic invoice. */
    public function test_the_view_tick_alone_shows_a_template_and_its_preview(): void
    {
        $this->holding(['documents.template_view']);

        $this->post('/api/v1/tenant/document-templates/preview', [
            'document_type' => 'clinic_invoice',
            'config' => TemplateConfig::defaultsFor('clinic_invoice'),
        ])->assertOk()->assertHeader('content-type', 'application/pdf');

        // Reading is all it opens.
        $this->putJson('/api/v1/tenant/document-templates/1', [])->assertForbidden();
    }

    public function test_the_template_editor_reads_only_a_branch_it_works_at(): void
    {
        $this->holding(['documents.template_view']);

        $this->getJson("/api/v1/tenant/locations/{$this->here}")->assertOk();
        $this->getJson("/api/v1/tenant/locations/{$this->elsewhere}")->assertForbidden();

        // Without the tick there is nothing to read it for.
        $this->holding(['dashboard.view']);
        $this->getJson("/api/v1/tenant/locations/{$this->here}")->assertForbidden();

        // The Branches screen still reads every branch.
        $this->signInAsOwner($this->organization);
        $this->getJson("/api/v1/tenant/locations/{$this->elsewhere}")->assertOk();
    }

    public function test_the_logo_is_changed_under_the_edit_tick_at_their_own_branch_only(): void
    {
        Storage::fake('public');

        $logo = fn () => ['logo' => UploadedFile::fake()->image('logo.png', 120, 120)];

        $this->holding(['documents.template_view']);
        $this->post("/api/v1/tenant/locations/{$this->here}/logo", $logo(), ['Accept' => 'application/json'])->assertForbidden();

        $this->holding(['documents.template_view', 'documents.template_edit']);
        $this->post("/api/v1/tenant/locations/{$this->elsewhere}/logo", $logo(), ['Accept' => 'application/json'])->assertForbidden();
        $this->post("/api/v1/tenant/locations/{$this->here}/logo", $logo(), ['Accept' => 'application/json'])->assertOk();

        // What the editor reads back is the mark it just uploaded.
        $branch = $this->getJson("/api/v1/tenant/locations/{$this->here}")->assertOk();

        $this->assertTrue($branch->json('data.has_logo'));
        $this->assertNotNull($branch->json('data.logo_url'));
    }

    /** The requests the page makes when somebody with only their branch's ticks saves. */
    public function test_a_branch_saving_the_default_gets_its_own_copy(): void
    {
        $this->holding(['documents.template_view', 'documents.template_edit', 'documents.template_publish']);

        $default = collect($this->getJson('/api/v1/tenant/document-templates')->assertOk()->json('data'))
            ->first(fn (array $row) => $row['document_type'] === 'clinic_invoice' && $row['is_organization_default']);

        $this->assertNotNull($default);

        $config = TemplateConfig::defaultsFor('clinic_invoice');

        // The default stays the organization's.
        $this->putJson("/api/v1/tenant/document-templates/{$default['id']}", [
            'name' => $default['name'],
            'config' => $config,
        ])->assertForbidden();

        $own = $this->postJson('/api/v1/tenant/document-templates', [
            'document_type' => 'clinic_invoice',
            'location_id' => $this->here,
            'name' => $default['name'],
            'config' => $config,
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/tenant/document-templates/{$own['id']}/publish")->assertOk();

        // Saving again goes to the copy, and the server insists on the name the page now sends.
        $this->putJson("/api/v1/tenant/document-templates/{$own['id']}", ['config' => $config])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->putJson("/api/v1/tenant/document-templates/{$own['id']}", [
            'name' => $default['name'],
            'config' => $config,
        ])->assertOk();

        // Somewhere else is still not theirs.
        $this->postJson('/api/v1/tenant/document-templates', [
            'document_type' => 'clinic_invoice',
            'location_id' => $this->elsewhere,
            'name' => $default['name'],
            'config' => $config,
        ])->assertForbidden();
    }

    /** The registry's own answer, for what the role screen and the save both ask. */
    public function test_the_registry_says_the_same_thing_both_ways(): void
    {
        foreach (self::SETTINGS_TICKS as $tick) {
            $this->assertTrue(ModuleRegistry::branchRoleMayHold($tick), $tick);
        }

        $this->assertTrue(ModuleRegistry::branchRoleMayHold('customers.view'));
        $this->assertFalse(ModuleRegistry::branchRoleMayHold('branches.create'));
        $this->assertFalse(ModuleRegistry::branchRoleMayHold('no.such_tick'));
    }
}
