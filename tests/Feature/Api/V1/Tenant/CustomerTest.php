<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Tenant\Customer;
use App\Models\Tenant\EntityFieldSetting;
use App\Models\Tenant\Location;
use App\Models\Tenant\User as TenantUser;
use App\Services\Tenancy\TenantConnectionService;
use App\Support\Fields\CustomerFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TenantTestCase;

/**
 * The customer record the whole organization shares.
 *
 * The point of this table is that one person's history adds up across every
 * store, so the test that matters most is the one asserting it carries no
 * location of its own.
 */
class CustomerTest extends TenantTestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Rahul Verma',
            'phone' => '98765'.random_int(10000, 99999),
            'is_active' => true,
        ], $overrides);
    }

    /**
     * The whole reason this table exists: a customer belongs to the
     * organization, so one person's history is not split per branch.
     */
    public function test_a_customer_is_not_tied_to_a_location(): void
    {
        $organization = $this->provisionOrganization();

        (new TenantConnectionService())->connect($organization->database_name);
        $columns = Schema::connection('organization')->getColumnListing('customers');
        (new TenantConnectionService())->disconnect();

        $this->assertNotContains('location_id', $columns);
        $this->assertNotContains('store_id', $columns);
    }

    /** Whoever is at the counter has to be able to add one. */
    public function test_staff_can_add_and_edit_customers(): void
    {
        $organization = $this->provisionOrganization();
        $this->signIn($organization, self::STAFF_EMAIL);

        $id = $this->postJson('/api/v1/tenant/customers', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.name', 'Rahul Verma')
            ->json('data.id');

        $this->putJson("/api/v1/tenant/customers/{$id}", $this->payload([
            'name' => 'Rahul K Verma',
        ]))->assertOk()->assertJsonPath('data.name', 'Rahul K Verma');

        $this->getJson('/api/v1/tenant/customers')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    /** Removing somebody's history is an owner decision, not a counter one. */
    public function test_only_the_owner_can_remove_a_customer(): void
    {
        $organization = $this->provisionOrganization();
        $this->signIn($organization, self::STAFF_EMAIL);

        $id = $this->postJson('/api/v1/tenant/customers', $this->payload())
            ->assertCreated()
            ->json('data.id');

        $this->deleteJson("/api/v1/tenant/customers/{$id}")->assertStatus(403);

        $this->postJson('/api/v1/tenant/auth/logout')->assertOk();
        $this->signIn($organization, $organization->email);

        $this->deleteJson("/api/v1/tenant/customers/{$id}")->assertOk();
    }

    public function test_a_phone_number_cannot_be_shared_by_two_live_customers(): void
    {
        $organization = $this->provisionOrganization();
        $this->signIn($organization, $organization->email);

        $phone = '9876500001';

        $this->postJson('/api/v1/tenant/customers', $this->payload(['phone' => $phone]))
            ->assertCreated();

        $this->postJson('/api/v1/tenant/customers', $this->payload(['phone' => $phone]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    /**
     * Postgres treats NULLs as distinct in a unique index, so any number of
     * walk-ins can be recorded without one.
     */
    public function test_several_customers_may_have_no_phone_at_all(): void
    {
        $organization = $this->provisionOrganization();
        $this->signIn($organization, $organization->email);

        foreach (['Walk-in One', 'Walk-in Two', 'Walk-in Three'] as $name) {
            $this->postJson('/api/v1/tenant/customers', [
                'name' => $name,
                'is_active' => true,
            ])->assertCreated();
        }

        $this->getJson('/api/v1/tenant/customers')->assertOk()->assertJsonPath('meta.total', 3);
    }

    public function test_a_removed_customers_phone_can_be_used_again(): void
    {
        $organization = $this->provisionOrganization();
        $this->signIn($organization, $organization->email);

        $phone = '9876500002';

        $id = $this->postJson('/api/v1/tenant/customers', $this->payload(['phone' => $phone]))
            ->assertCreated()
            ->json('data.id');

        $this->deleteJson("/api/v1/tenant/customers/{$id}")->assertOk();

        $this->postJson('/api/v1/tenant/customers', $this->payload(['phone' => $phone]))
            ->assertCreated();
    }

    public function test_a_future_date_of_birth_is_rejected(): void
    {
        $organization = $this->provisionOrganization();
        $this->signIn($organization, $organization->email);

        $this->postJson('/api/v1/tenant/customers', $this->payload([
            'date_of_birth' => now()->addYear()->toDateString(),
        ]))->assertStatus(422)->assertJsonValidationErrors('date_of_birth');
    }

    public function test_the_age_is_derived_from_the_date_of_birth(): void
    {
        $organization = $this->provisionOrganization();
        $this->signIn($organization, $organization->email);

        $this->postJson('/api/v1/tenant/customers', $this->payload([
            'date_of_birth' => now()->subYears(30)->toDateString(),
            'gender' => Customer::MALE,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.age', 30)
            ->assertJsonPath('data.gender', Customer::MALE);
    }

    /**
     * Which branch signed somebody up is recorded and reportable — but it is
     * provenance, not ownership: staff at any location still see everyone.
     */
    public function test_the_registering_branch_is_recorded_and_filterable(): void
    {
        $organization = $this->provisionOrganization();

        $location = $this->onTenant($organization, fn () => Location::on('organization')->create([
            'name' => 'Main Street',
            'code' => 'MS-01',
            'type' => Location::RETAIL_STORE,
            'is_active' => true,
        ]));

        $this->signInAsOwner($organization);

        $this->postJson('/api/v1/tenant/customers', $this->payload([
            'registered_location_id' => $location->id,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.registered_location_id', $location->id);

        // Somebody with no branch on file.
        $this->postJson('/api/v1/tenant/customers', $this->payload(['name' => 'Walk-in']))
            ->assertCreated();

        $this->getJson("/api/v1/tenant/customers?registered_location_id={$location->id}")
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/v1/tenant/customers')->assertOk()->assertJsonPath('meta.total', 2);

        $stats = $this->getJson('/api/v1/tenant/customers/stats')->assertOk()->json('data');

        $this->assertSame(2, $stats['total']);
        $this->assertEqualsCanonicalizing(
            ['Main Street', 'Not recorded'],
            array_column($stats['by_location'], 'label')
        );
    }

    /** Staff see every customer, whichever branch registered them. */
    public function test_the_registering_branch_does_not_limit_who_can_see_a_customer(): void
    {
        $organization = $this->provisionOrganization();

        $location = $this->onTenant($organization, fn () => Location::on('organization')->create([
            'name' => 'Other Branch',
            'code' => 'OB-01',
            'type' => Location::RETAIL_STORE,
            'is_active' => true,
        ]));

        $this->signInAsOwner($organization);

        $this->postJson('/api/v1/tenant/customers', $this->payload([
            'registered_location_id' => $location->id,
        ]))->assertCreated();

        $this->postJson('/api/v1/tenant/auth/logout')->assertOk();
        $this->signInAsStaff($organization);

        $this->getJson('/api/v1/tenant/customers')->assertOk()->assertJsonPath('meta.total', 1);
    }

    /**
     * Somebody who works at a branch has their own recorded for them.
     *
     * The second half is the part that matters: overriding whatever was
     * submitted, not merely hiding the field, is what makes "you cannot
     * record the wrong branch" true for anything calling the API directly.
     */
    public function test_a_branch_users_own_branch_is_recorded_and_cannot_be_overridden(): void
    {
        $organization = $this->provisionOrganization();

        [$mine, $theirs] = $this->onTenant($organization, function () {
            $mine = Location::on('organization')->create([
                'name' => 'My Branch', 'code' => 'MB-1',
                'type' => Location::RETAIL_STORE, 'is_active' => true,
            ]);

            $theirs = Location::on('organization')->create([
                'name' => 'Other Branch', 'code' => 'OB-1',
                'type' => Location::RETAIL_STORE, 'is_active' => true,
            ]);

            TenantUser::on('organization')
                ->where('email', self::STAFF_EMAIL)
                ->update(['location_id' => $mine->id]);

            return [$mine, $theirs];
        });

        $this->signInAsStaff($organization);

        // Nothing sent — their branch is filled in anyway.
        $this->postJson('/api/v1/tenant/customers', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.registered_location_id', $mine->id);

        // Another branch sent deliberately — still recorded as their own.
        $this->postJson('/api/v1/tenant/customers', $this->payload([
            'registered_location_id' => $theirs->id,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.registered_location_id', $mine->id);

        // And the field is off their form entirely.
        $fields = $this->getJson('/api/v1/tenant/customers/fields')->assertOk()->json('data');
        $branchField = collect($fields)->firstWhere('key', 'registered_location_id');

        $this->assertFalse($branchField['show_in_form']);
    }

    /** The owner works across the network, so they still choose. */
    public function test_the_owner_still_picks_the_branch(): void
    {
        $organization = $this->provisionOrganization();

        $location = $this->onTenant($organization, fn () => Location::on('organization')->create([
            'name' => 'Some Branch', 'code' => 'SB-1',
            'type' => Location::RETAIL_STORE, 'is_active' => true,
        ]));

        $this->signInAsOwner($organization);

        $fields = $this->getJson('/api/v1/tenant/customers/fields')->assertOk()->json('data');
        $branchField = collect($fields)->firstWhere('key', 'registered_location_id');

        $this->assertTrue($branchField['show_in_form']);

        $this->postJson('/api/v1/tenant/customers', $this->payload([
            'registered_location_id' => $location->id,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.registered_location_id', $location->id);
    }

    /** The same configuration layer Locations and People use. */
    public function test_the_customer_form_is_configurable(): void
    {
        $organization = $this->provisionOrganization();
        $this->signIn($organization, $organization->email);

        $fields = array_map(
            fn (array $field, int $index) => [
                'field_key' => $field['key'],
                'label' => $field['label'],
                'placeholder' => $field['placeholder'] ?? null,
                'is_custom' => false,
                'data_type' => null,
                'options' => null,
                // Phone is optional in the registry; this organization always
                // wants one.
                'is_required' => $field['key'] === 'phone' ? true : $field['required'],
                'show_in_form' => true,
                'show_in_table' => $field['in_table'],
                'sort_order' => $index,
            ],
            CustomerFields::all(),
            array_keys(CustomerFields::all())
        );

        $fields[] = [
            'field_key' => 'loyalty_tier',
            'label' => 'Loyalty tier',
            'placeholder' => null,
            'is_custom' => true,
            'data_type' => EntityFieldSetting::TYPE_SELECT,
            'options' => [
                ['value' => 'silver', 'label' => 'Silver'],
                ['value' => 'gold', 'label' => 'Gold'],
            ],
            'is_required' => false,
            'show_in_form' => true,
            'show_in_table' => true,
            'sort_order' => 90,
        ];

        $this->putJson('/api/v1/tenant/settings/fields/customer', ['fields' => $fields])
            ->assertOk();

        // Phone is mandatory now.
        $this->postJson('/api/v1/tenant/customers', ['name' => 'No Phone'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');

        // And the custom dropdown only accepts its own options.
        $this->postJson('/api/v1/tenant/customers', $this->payload([
            'custom_fields' => ['loyalty_tier' => 'platinum'],
        ]))->assertStatus(422)->assertJsonValidationErrors('custom_fields.loyalty_tier');

        $this->postJson('/api/v1/tenant/customers', $this->payload([
            'custom_fields' => ['loyalty_tier' => 'gold'],
        ]))
            ->assertCreated()
            ->assertJsonPath('data.custom_fields.loyalty_tier', 'gold');
    }
}
