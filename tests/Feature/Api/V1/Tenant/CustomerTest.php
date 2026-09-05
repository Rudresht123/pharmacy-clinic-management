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

    /*
    |--------------------------------------------------------------------------
    | The patient number
    |--------------------------------------------------------------------------
    */

    /**
     * Everybody registered gets a number, in sequence.
     *
     * Until this existed a patient was identified by name and phone, so two
     * people called Rahul Sharma were indistinguishable in a search result and
     * in the OPD queue, and there was nothing to read out over a counter.
     */
    public function test_a_customer_is_given_a_number_in_sequence(): void
    {
        $organization = $this->provisionOrganization();
        $this->signInAsOwner($organization);

        $first = $this->postJson('/api/v1/tenant/customers', $this->payload())
            ->assertCreated()->json('data.code');

        $second = $this->postJson('/api/v1/tenant/customers', $this->payload([
            'name' => 'Second Patient',
        ]))->assertCreated()->json('data.code');

        $this->assertSame('P-00001', $first);
        $this->assertSame('P-00002', $second);
    }

    /**
     * The next number is read off the highest one, not off the row count.
     *
     * Counting rows repeats a number the moment anybody is deleted, and the
     * partial unique index would then reject the second registration — a
     * failure at the counter with no explanation the receptionist could act
     * on.
     */
    public function test_the_number_after_a_deletion_does_not_repeat(): void
    {
        $organization = $this->provisionOrganization();
        $this->signInAsOwner($organization);

        $first = $this->postJson('/api/v1/tenant/customers', $this->payload())
            ->assertCreated()->json('data');

        $this->postJson('/api/v1/tenant/customers', $this->payload(['name' => 'Second']))
            ->assertCreated();

        $this->deleteJson("/api/v1/tenant/customers/{$first['id']}")->assertOk();

        $this->postJson('/api/v1/tenant/customers', $this->payload(['name' => 'Third']))
            ->assertCreated()
            ->assertJsonPath('data.code', 'P-00003');
    }

    /**
     * Compared as a number, not as a string.
     *
     * A plain string ordering puts P-00009 above P-00010, so the tenth
     * registration of the day would be handed a number that already exists.
     * Ten in a row is the cheapest way to cross that boundary for real.
     */
    public function test_the_sequence_crosses_a_digit_boundary(): void
    {
        $organization = $this->provisionOrganization();
        $this->signInAsOwner($organization);

        for ($n = 1; $n <= 11; $n++) {
            $this->postJson('/api/v1/tenant/customers', $this->payload([
                'name' => "Patient {$n}",
            ]))->assertCreated();
        }

        $this->getJson('/api/v1/tenant/customers?search=P-00010')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Patient 10');
    }

    /** An organization migrating from another system keeps its own numbers. */
    public function test_a_supplied_number_is_kept(): void
    {
        $organization = $this->provisionOrganization();
        $this->signInAsOwner($organization);

        $this->postJson('/api/v1/tenant/customers', $this->payload(['code' => 'UHID-4471']))
            ->assertCreated()
            ->assertJsonPath('data.code', 'UHID-4471');
    }

    /**
     * The whole reason this table exists: a customer belongs to the
     * organization, so one person's history is not split per branch.
     */
    public function test_a_customer_is_not_tied_to_a_location(): void
    {
        $organization = $this->provisionOrganization();

        (new TenantConnectionService)->connect($organization->database_name);
        $columns = Schema::connection('organization')->getColumnListing('customers');
        (new TenantConnectionService)->disconnect();

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

            return [$mine, $theirs];
        });

        $this->placeStaffAt($organization, $mine->id);

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

    /**
     * The monthly series is twelve buckets whether or not anybody joined.
     *
     * A month with nobody has to come back as zero rather than be skipped —
     * a missing bucket would draw as a shorter axis instead of a quiet
     * month, which reads as the opposite of what happened.
     */
    public function test_the_monthly_series_fills_in_quiet_months(): void
    {
        $organization = $this->provisionOrganization();
        $this->signInAsOwner($organization);

        $this->postJson('/api/v1/tenant/customers', $this->payload())->assertCreated();

        $months = $this->getJson('/api/v1/tenant/customers/stats')
            ->assertOk()
            ->json('data.by_month');

        $this->assertCount(12, $months);

        // Oldest first, ending on the current month.
        $this->assertSame(now()->format('Y-m'), end($months)['month']);
        $this->assertSame(1, end($months)['total']);

        // The eleven before it are real zeroes, not gaps.
        foreach (array_slice($months, 0, 11) as $bucket) {
            $this->assertSame(0, $bucket['total']);
        }
    }

    /**
     * Age bands are ordinal, and their boundaries are inclusive.
     *
     * The band a person falls in comes from a CASE built in PHP from a list
     * of upper bounds, so an off-by-one there would silently file a
     * thirteen-year-old as a child. Exercised on the boundaries themselves
     * rather than on comfortable middles, and asserted in order — the
     * screen renders this series unsorted, so the order is part of the
     * contract, not a coincidence.
     */
    public function test_age_bands_are_bounded_and_stay_in_order(): void
    {
        $organization = $this->provisionOrganization();
        $this->signInAsOwner($organization);

        // One either side of the 12/13 boundary, and one well past the last.
        foreach ([12, 13, 70] as $age) {
            $this->postJson('/api/v1/tenant/customers', $this->payload([
                // Mid-year, so the test does not flip on a birthday.
                'date_of_birth' => now()->subYears($age)->subMonths(6)->toDateString(),
            ]))->assertCreated();
        }

        // Somebody with no date of birth at all.
        $this->postJson('/api/v1/tenant/customers', $this->payload())->assertCreated();

        $bands = $this->getJson('/api/v1/tenant/customers/stats')
            ->assertOk()
            ->json('data.by_age_band');

        $this->assertSame(
            ['0-12', '13-25', '26-40', '41-60', '61+', 'unknown'],
            array_column($bands, 'key'),
        );

        $totals = array_column($bands, 'total', 'key');

        $this->assertSame(1, $totals['0-12']);
        $this->assertSame(1, $totals['13-25']);
        $this->assertSame(0, $totals['26-40']);
        $this->assertSame(1, $totals['61+']);
        $this->assertSame(1, $totals['unknown']);

        // An absence is greyed on the chart rather than given a hue.
        $this->assertTrue($bands[5]['muted']);
        $this->assertFalse($bands[0]['muted']);
    }

    /**
     * Every gender option comes back, including the ones nobody has.
     *
     * A segment that is missing and a segment that is empty look the same
     * on a chart, and they do not mean the same thing.
     */
    public function test_the_gender_split_returns_every_option(): void
    {
        $organization = $this->provisionOrganization();
        $this->signInAsOwner($organization);

        $this->postJson('/api/v1/tenant/customers', $this->payload(['gender' => 'female']))
            ->assertCreated();

        // Left blank on the form.
        $this->postJson('/api/v1/tenant/customers', $this->payload())->assertCreated();

        $split = $this->getJson('/api/v1/tenant/customers/stats')
            ->assertOk()
            ->json('data.by_gender');

        $this->assertSame(['male', 'female', 'other', 'unknown'], array_column($split, 'key'));

        $totals = array_column($split, 'total', 'key');

        $this->assertSame(0, $totals['male']);
        $this->assertSame(1, $totals['female']);
        $this->assertSame(1, $totals['unknown']);
    }

    /**
     * One town is one bar, however it was typed.
     *
     * City is free text taken at a counter. Grouping on the raw string
     * would draw "pune" and "Pune" as two smaller towns, which is a wrong
     * answer rather than an untidy one.
     */
    public function test_towns_are_counted_once_however_they_were_typed(): void
    {
        $organization = $this->provisionOrganization();
        $this->signInAsOwner($organization);

        foreach (['Pune', 'pune', '  PUNE '] as $spelling) {
            $this->postJson('/api/v1/tenant/customers', $this->payload(['city' => $spelling]))
                ->assertCreated();
        }

        $this->postJson('/api/v1/tenant/customers', $this->payload(['city' => 'Nashik']))
            ->assertCreated();

        $towns = $this->getJson('/api/v1/tenant/customers/stats')
            ->assertOk()
            ->json('data.by_city');

        $this->assertSame(
            [['label' => 'Pune', 'total' => 3], ['label' => 'Nashik', 'total' => 1]],
            $towns,
        );
    }

    /**
     * The filter panel's fields actually narrow the list.
     *
     * Each is asserted to exclude somebody, not merely to include the row
     * that matches — a filter that is silently ignored still returns the
     * expected row, and would pass a weaker test.
     */
    public function test_the_listing_filters_narrow_the_results(): void
    {
        $organization = $this->provisionOrganization();
        $this->signInAsOwner($organization);

        $this->postJson('/api/v1/tenant/customers', $this->payload([
            'name' => 'Asha Rane',
            'gender' => 'female',
            'city' => 'Pune',
            'date_of_birth' => now()->subYears(30)->subMonths(6)->toDateString(),
        ]))->assertCreated();

        $this->postJson('/api/v1/tenant/customers', $this->payload([
            'name' => 'Vikram Shah',
            'gender' => 'male',
            'city' => 'Nashik',
            'date_of_birth' => now()->subYears(70)->subMonths(6)->toDateString(),
        ]))->assertCreated();

        $only = function (array $query, string $expected) {
            $names = $this->getJson('/api/v1/tenant/customers?'.http_build_query($query))
                ->assertOk()
                ->json('data.*.name');

            $this->assertSame([$expected], $names);
        };

        $only(['gender' => 'female'], 'Asha Rane');
        $only(['age_band' => '61+'], 'Vikram Shah');
        $only(['age_band' => '26-40'], 'Asha Rane');

        // Partial and differently cased, the way somebody actually types it.
        $only(['city' => 'nash'], 'Vikram Shah');
    }

    /**
     * A window filter counts from today, not from a calendar boundary.
     *
     * Also proves the filter is applied at all: without it both rows come
     * back, and the assertion on the total would not notice.
     */
    public function test_the_joined_window_excludes_older_records(): void
    {
        $organization = $this->provisionOrganization();
        $this->signInAsOwner($organization);

        $this->postJson('/api/v1/tenant/customers', $this->payload(['name' => 'Recent']))
            ->assertCreated();

        $this->postJson('/api/v1/tenant/customers', $this->payload(['name' => 'Old']))
            ->assertCreated();

        // Backdated past the window, in the tenant database.
        $this->onTenant($organization, function () {
            Customer::on('organization')
                ->where('name', 'Old')
                ->update(['created_at' => now()->subDays(45)]);
        });

        $names = $this->getJson('/api/v1/tenant/customers?joined_within=30')
            ->assertOk()
            ->json('data.*.name');

        $this->assertSame(['Recent'], $names);

        // Unfiltered, both are still there — the row was backdated, not lost.
        $this->assertCount(
            2,
            $this->getJson('/api/v1/tenant/customers')->assertOk()->json('data')
        );
    }

    /**
     * A band the application does not know is ignored, not obeyed.
     *
     * A stale bookmark should show the whole list rather than an empty one,
     * which would read as "you have no patients".
     */
    public function test_an_unknown_age_band_is_ignored(): void
    {
        $organization = $this->provisionOrganization();
        $this->signInAsOwner($organization);

        $this->postJson('/api/v1/tenant/customers', $this->payload())->assertCreated();

        $this->assertCount(
            1,
            $this->getJson('/api/v1/tenant/customers?age_band=nonsense')
                ->assertOk()
                ->json('data')
        );
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
