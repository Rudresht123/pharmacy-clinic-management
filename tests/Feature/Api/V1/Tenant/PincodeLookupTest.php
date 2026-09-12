<?php

namespace Tests\Feature\Api\V1\Tenant;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TenantTestCase;

/**
 * Turning a PIN code into a place.
 *
 * India Post is faked throughout: a test that reached the real service would
 * fail whenever it did, and would be asking a public API the same question on
 * every run.
 */
class PincodeLookupTest extends TenantTestCase
{
    use RefreshDatabase;

    private const URL = 'api.postalpincode.in/*';

    /** @return array<string, mixed> */
    private function office(string $name, array $overrides = []): array
    {
        return array_merge([
            'Name' => $name,
            'Description' => null,
            'BranchType' => 'Sub Post Office',
            'DeliveryStatus' => 'Delivery',
            'Circle' => 'Uttar Pradesh',
            'District' => 'Gautam Buddha Nagar',
            'Division' => 'Ghaziabad',
            'Region' => 'Agra',
            'Block' => 'Dadri',
            'State' => 'Uttar Pradesh',
            'Country' => 'India',
            'Pincode' => '201301',
        ], $overrides);
    }

    /**
     * India Post's own shape: a one-element list.
     *
     * @param  list<array<string, mixed>>  $offices
     * @return list<array<string, mixed>>
     */
    private function found(array $offices): array
    {
        return [[
            'Message' => 'Number of pincode(s) found:'.count($offices),
            'Status' => 'Success',
            'PostOffice' => $offices,
        ]];
    }

    /** @return list<array<string, mixed>> */
    private function notFound(): array
    {
        return [['Message' => 'No records found', 'Status' => 'Error', 'PostOffice' => null]];
    }

    public function test_a_pincode_answers_with_its_state_district_and_areas(): void
    {
        Http::fake([self::URL => Http::response($this->found([
            $this->office('Noida Sector 12'),
            $this->office('Noida Sector 1'),
        ]))]);

        $organization = $this->provisionOrganization();
        $this->signInAsOwner($organization);

        $this->getJson('/api/v1/tenant/lookups/pincode/201301')
            ->assertOk()
            ->assertJsonPath('data.pincode', '201301')
            ->assertJsonPath('data.country', 'India')
            ->assertJsonPath('data.state', 'Uttar Pradesh')
            ->assertJsonPath('data.district', 'Gautam Buddha Nagar')
            ->assertJsonCount(2, 'data.areas')
            // Sorted by name, naturally, so "Sector 1" comes before "Sector 12".
            ->assertJsonPath('data.areas.0.name', 'Noida Sector 1')
            ->assertJsonPath('data.areas.0.block', 'Dadri')
            ->assertJsonPath('data.areas.0.delivery', true);
    }

    /**
     * A PIN code that straddles a boundary is answered with the district most
     * of its offices sit in, not whichever India Post happened to list first.
     */
    public function test_the_district_is_the_one_most_offices_agree_on(): void
    {
        Http::fake([self::URL => Http::response($this->found([
            $this->office('Border Village', ['District' => 'Ghaziabad']),
            $this->office('Noida Sector 1'),
            $this->office('Noida Sector 2'),
        ]))]);

        $organization = $this->provisionOrganization();
        $this->signInAsOwner($organization);

        $this->getJson('/api/v1/tenant/lookups/pincode/201301')
            ->assertOk()
            ->assertJsonPath('data.district', 'Gautam Buddha Nagar');
    }

    /** PIN codes do not move, so each is asked of India Post once. */
    public function test_a_pincode_is_asked_of_india_post_only_once(): void
    {
        Http::fake([self::URL => Http::response($this->found([$this->office('Noida Sector 1')]))]);

        $organization = $this->provisionOrganization();
        $this->signInAsOwner($organization);

        $this->getJson('/api/v1/tenant/lookups/pincode/201301')->assertOk();
        $this->getJson('/api/v1/tenant/lookups/pincode/201301')->assertOk();

        Http::assertSentCount(1);
    }

    public function test_an_unknown_pincode_is_not_found(): void
    {
        Http::fake([self::URL => Http::response($this->notFound())]);

        $organization = $this->provisionOrganization();
        $this->signInAsOwner($organization);

        $this->getJson('/api/v1/tenant/lookups/pincode/999999')
            ->assertNotFound()
            ->assertJsonPath('message', 'No post office has this PIN code.');
    }

    /**
     * An outage is reported as an outage — and forgotten.
     *
     * If a failure were cached like a miss, a real address typed during a
     * two-minute India Post outage would be refused as "no such PIN code" for
     * the rest of the day.
     */
    public function test_an_unreachable_service_is_reported_and_not_remembered(): void
    {
        Http::fake([self::URL => Http::sequence()
            ->pushStatus(500)
            ->push($this->found([$this->office('Noida Sector 1')]))]);

        $organization = $this->provisionOrganization();
        $this->signInAsOwner($organization);

        $this->getJson('/api/v1/tenant/lookups/pincode/201301')->assertStatus(503);

        $this->getJson('/api/v1/tenant/lookups/pincode/201301')
            ->assertOk()
            ->assertJsonPath('data.state', 'Uttar Pradesh');
    }

    public function test_something_that_is_not_a_pincode_never_reaches_india_post(): void
    {
        Http::fake();

        $organization = $this->provisionOrganization();
        $this->signInAsOwner($organization);

        // Five digits, a leading zero, letters: none is a PIN code.
        $this->getJson('/api/v1/tenant/lookups/pincode/20130')->assertNotFound();
        $this->getJson('/api/v1/tenant/lookups/pincode/012345')->assertNotFound();
        $this->getJson('/api/v1/tenant/lookups/pincode/20130a')->assertNotFound();

        Http::assertNothingSent();
    }

    /** Not an open relay to India Post for anybody on the internet. */
    public function test_the_lookup_needs_a_signed_in_user(): void
    {
        Http::fake();

        $this->getJson('/api/v1/tenant/lookups/pincode/201301')->assertUnauthorized();

        Http::assertNothingSent();
    }

    /** What the lookup fills in is kept on the patient. */
    public function test_a_patient_keeps_the_district_and_country(): void
    {
        $organization = $this->provisionOrganization();
        $this->signInAsOwner($organization);

        $this->postJson('/api/v1/tenant/customers', [
            'name' => 'Asha Rane',
            'pincode' => '201301',
            'city' => 'Noida Sector 1',
            'district' => 'Gautam Buddha Nagar',
            'state' => 'Uttar Pradesh',
            'country' => 'India',
        ])
            ->assertCreated()
            ->assertJsonPath('data.district', 'Gautam Buddha Nagar')
            ->assertJsonPath('data.country', 'India');
    }
}
