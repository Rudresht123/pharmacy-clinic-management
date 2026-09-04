<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\EntityFieldSetting;
use App\Models\Tenant\Location;
use App\Support\Fields\CustomerFields;
use App\Support\Fields\LocationFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TenantTestCase;

/**
 * Per-organization field configuration: what is mandatory, what is shown,
 * and the fields an organization adds for itself.
 */
class FieldSettingTest extends TenantTestCase
{
    use RefreshDatabase;

    /**
     * The settings screen always posts the whole set, so tests do too.
     *
     * @param  array<string, array<string, mixed>>  $overrides  keyed by field_key
     * @return list<array<string, mixed>>
     */
    private function payloadFromRegistry(
        array $overrides = [],
        array $extra = [],
        ?array $registry = null,
    ): array {
        $fields = [];

        foreach (array_values($registry ?? LocationFields::all()) as $index => $field) {
            $fields[] = array_merge([
                'field_key' => $field['key'],
                'label' => $field['label'],
                'placeholder' => $field['placeholder'] ?? null,
                'is_custom' => false,
                'data_type' => null,
                'options' => null,
                'is_required' => $field['required'],
                'show_in_form' => true,
                'show_in_table' => $field['in_table'],
                'sort_order' => $index,
            ], $overrides[$field['key']] ?? []);
        }

        return array_merge($fields, $extra);
    }

    /** @return array<string, mixed> */
    private function validLocation(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Main Street Pharmacy',
            'code' => 'MSP-'.uniqid(),
            'type' => Location::RETAIL_STORE,
            'is_active' => true,
        ], $overrides);
    }

    public function test_the_registry_is_returned_when_nothing_is_configured(): void
    {
        $organization = $this->provisionOrganization();
        $this->signIn($organization, $organization->email);

        $response = $this->getJson('/api/v1/tenant/settings/fields/location')->assertOk();

        $this->assertSame(
            array_column(LocationFields::all(), 'key'),
            array_column($response->json('data.fields'), 'key'),
            'Unconfigured, the effective schema is exactly the registry.'
        );
    }

    public function test_only_the_owner_may_save_settings(): void
    {
        $organization = $this->provisionOrganization();
        $this->signIn($organization, self::STAFF_EMAIL);

        // Staff need to read them — the location form is built from them.
        $this->getJson('/api/v1/tenant/settings/fields/location')->assertOk();

        $this->putJson('/api/v1/tenant/settings/fields/location', [
            'fields' => $this->payloadFromRegistry(),
        ])->assertStatus(403);
    }

    /**
     * The whole point of the feature: a field the organization marks
     * mandatory has to be enforced by the API, not merely starred in the UI.
     */
    public function test_a_field_marked_mandatory_is_enforced_on_write(): void
    {
        $organization = $this->provisionOrganization();
        $this->signIn($organization, $organization->email);

        // City is optional in the registry.
        $this->postJson('/api/v1/tenant/locations', $this->validLocation())->assertCreated();

        $this->putJson('/api/v1/tenant/settings/fields/location', [
            'fields' => $this->payloadFromRegistry(['city' => ['is_required' => true]]),
        ])->assertOk();

        $this->postJson('/api/v1/tenant/locations', $this->validLocation())
            ->assertStatus(422)
            ->assertJsonValidationErrors('city');

        $this->postJson('/api/v1/tenant/locations', $this->validLocation(['city' => 'Pune']))
            ->assertCreated();
    }

    public function test_a_locked_field_cannot_be_hidden_or_made_optional(): void
    {
        $organization = $this->provisionOrganization();
        $this->signIn($organization, $organization->email);

        $this->putJson('/api/v1/tenant/settings/fields/location', [
            'fields' => $this->payloadFromRegistry([
                'name' => ['show_in_form' => false, 'is_required' => false],
            ]),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['fields.0.show_in_form', 'fields.0.is_required']);
    }

    public function test_an_organization_can_add_and_store_its_own_field(): void
    {
        $organization = $this->provisionOrganization();
        $this->signIn($organization, $organization->email);

        $this->putJson('/api/v1/tenant/settings/fields/location', [
            'fields' => $this->payloadFromRegistry([], [[
                'field_key' => 'shelf_count',
                'label' => 'Shelf count',
                'placeholder' => '24',
                'is_custom' => true,
                'data_type' => EntityFieldSetting::TYPE_NUMBER,
                'options' => null,
                'is_required' => true,
                'show_in_form' => true,
                'show_in_table' => true,
                'sort_order' => 100,
            ]]),
        ])->assertOk();

        // Now mandatory, so a location without it is refused…
        $this->postJson('/api/v1/tenant/locations', $this->validLocation())
            ->assertStatus(422)
            ->assertJsonValidationErrors('custom_fields.shelf_count');

        // …and its declared type is enforced.
        $this->postJson('/api/v1/tenant/locations', $this->validLocation([
            'custom_fields' => ['shelf_count' => 'not a number'],
        ]))->assertStatus(422)->assertJsonValidationErrors('custom_fields.shelf_count');

        $this->postJson('/api/v1/tenant/locations', $this->validLocation([
            'custom_fields' => ['shelf_count' => 24],
        ]))
            ->assertCreated()
            ->assertJsonPath('data.custom_fields.shelf_count', 24);
    }

    public function test_a_custom_field_cannot_take_a_built_in_name(): void
    {
        $organization = $this->provisionOrganization();
        $this->signIn($organization, $organization->email);

        $this->putJson('/api/v1/tenant/settings/fields/location', [
            'fields' => $this->payloadFromRegistry([], [[
                'field_key' => 'city',
                'label' => 'My city',
                'placeholder' => null,
                'is_custom' => true,
                'data_type' => EntityFieldSetting::TYPE_TEXT,
                'options' => null,
                'is_required' => false,
                'show_in_form' => true,
                'show_in_table' => false,
                'sort_order' => 100,
            ]]),
        ])->assertStatus(422);
    }

    /** Removing a field from the payload deletes it. */
    public function test_saving_replaces_the_previous_configuration(): void
    {
        $organization = $this->provisionOrganization();
        $this->signIn($organization, $organization->email);

        $custom = [[
            'field_key' => 'shelf_count',
            'label' => 'Shelf count',
            'placeholder' => null,
            'is_custom' => true,
            'data_type' => EntityFieldSetting::TYPE_NUMBER,
            'options' => null,
            'is_required' => false,
            'show_in_form' => true,
            'show_in_table' => false,
            'sort_order' => 100,
        ]];

        $this->putJson('/api/v1/tenant/settings/fields/location', [
            'fields' => $this->payloadFromRegistry([], $custom),
        ])->assertOk();

        $withCustom = $this->getJson('/api/v1/tenant/settings/fields/location')->json('data.fields');
        $this->assertContains('shelf_count', array_column($withCustom, 'key'));

        $this->putJson('/api/v1/tenant/settings/fields/location', [
            'fields' => $this->payloadFromRegistry(),
        ])->assertOk();

        $without = $this->getJson('/api/v1/tenant/settings/fields/location')->json('data.fields');
        $this->assertNotContains('shelf_count', array_column($without, 'key'));
    }

    public function test_a_dropdown_field_needs_options(): void
    {
        $organization = $this->provisionOrganization();
        $this->signIn($organization, $organization->email);

        $this->putJson('/api/v1/tenant/settings/fields/location', [
            'fields' => $this->payloadFromRegistry([], [[
                'field_key' => 'ownership',
                'label' => 'Ownership',
                'placeholder' => null,
                'is_custom' => true,
                'data_type' => EntityFieldSetting::TYPE_SELECT,
                'options' => [],
                'is_required' => false,
                'show_in_form' => true,
                'show_in_table' => false,
                'sort_order' => 100,
            ]]),
        ])->assertStatus(422);
    }

    /**
     * The same person is a customer at a pharmacy counter and a patient in a
     * clinic. One record either way — only the wording moves.
     */
    public function test_an_organization_can_rename_what_it_calls_a_record(): void
    {
        $organization = $this->provisionOrganization();
        $this->signInAsOwner($organization);

        $this->getJson('/api/v1/tenant/settings/fields/customer')
            ->assertOk()
            ->assertJsonPath('data.label', 'Customers')
            ->assertJsonPath('data.singular', 'Customer');

        $this->putJson('/api/v1/tenant/settings/fields/customer', [
            'fields' => $this->payloadFromRegistry([], [], CustomerFields::all()),
            'label' => ['singular' => 'Patient', 'plural' => 'Patients'],
        ])
            ->assertOk()
            ->assertJsonPath('data.label', 'Patients')
            ->assertJsonPath('data.singular', 'Patient');

        // It sticks, and the tab list uses it too.
        $this->getJson('/api/v1/tenant/settings/fields/customer')
            ->assertOk()
            ->assertJsonPath('data.label', 'Patients');

        $entities = $this->getJson('/api/v1/tenant/settings/fields')->assertOk()->json('data');
        $customer = collect($entities)->firstWhere('entity', 'customer');

        $this->assertSame('Patients', $customer['label']);
    }
}
