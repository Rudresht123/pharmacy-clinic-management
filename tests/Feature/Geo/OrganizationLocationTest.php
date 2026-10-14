<?php

namespace Tests\Feature\Geo;

use App\Models\Platform\Organization;
use App\Models\Platform\OrganizationType;
use App\Models\Platform\PlatformRole;
use App\Models\Platform\PlatformUser;
use App\Services\Geo\Geocoder;
use App\Services\Geo\GeocodingUnavailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Where an organization is on the map.
 *
 * What matters: an Indian address that a search engine cannot read still
 * lands somewhere honest (the locality, then the PIN code area), a pin
 * somebody placed by hand is kept exactly, and the dashboard shows only
 * points that were really found.
 */
class OrganizationLocationTest extends TestCase
{
    use RefreshDatabase;

    private PlatformUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = PlatformUser::factory()->withRole(PlatformRole::SUPER_ADMIN)->create();
    }

    private function organization(): Organization
    {
        $type = OrganizationType::create(['name' => 'Clinic', 'slug' => 'clinic', 'is_active' => true]);

        return Organization::factory()->active()->create(['organization_type_id' => $type->id]);
    }

    /** The fields the platform's update requires, as the organization already has them. */
    private function updatePayload(Organization $organization, array $extra): array
    {
        return [
            'organization_name' => $organization->organization_name,
            'organization_code' => $organization->organization_code,
            'organization_type_id' => $organization->organization_type_id,
            'subdomain' => $organization->subdomain,
            'email' => $organization->email,
            ...$extra,
        ];
    }

    public function test_a_noisy_address_falls_back_to_its_locality_then_the_pincode(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/search*' => Http::sequence()
                ->push([], 200)                                   // the address as written
                ->push([], 200)                                   // the locality
                ->push([['lat' => '28.5473960', 'lon' => '77.1935166']], 200), // the PIN code
        ]);

        $point = app(Geocoder::class)->locate('123, Green Park, Near City Hospital', 'New Delhi', 'Delhi', '110016');

        $this->assertSame(['latitude' => 28.547396, 'longitude' => 77.1935166, 'source' => 'pincode'], $point);

        $queries = Http::recorded()->map(fn ($pair) => $pair[0]->data());

        // The locality is asked without the house number or the landmark.
        $this->assertSame('Green Park, New Delhi, Delhi, India', $queries[1]['q']);
        $this->assertSame('110016', $queries[2]['postalcode']);
    }

    public function test_an_outage_is_reported_rather_than_read_as_not_found(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response('', 503)]);

        $this->expectException(GeocodingUnavailable::class);

        app(Geocoder::class)->locate(null, 'Lucknow', 'Uttar Pradesh', '226010');
    }

    public function test_a_hand_placed_pin_is_saved_exactly_as_sent(): void
    {
        Http::fake();
        $organization = $this->organization();

        $this->actingAs($this->admin, 'platform')
            ->putJson("/api/v1/admin/organizations/{$organization->uuid}", $this->updatePayload($organization, [
                'postal_code' => '226010',
                'city' => 'Lucknow',
                'state_province' => 'Uttar Pradesh',
                'latitude' => 26.8467,
                'longitude' => 80.9462,
                'location_source' => 'manual',
            ]))
            ->assertOk()
            ->assertJsonPath('data.location.latitude', 26.8467)
            ->assertJsonPath('data.location.location_source', 'manual');

        // Nothing was looked up: the pin is theirs.
        Http::assertNothingSent();
    }

    public function test_an_address_saved_without_a_pin_is_located_after_the_response(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/search*' => Http::response([['lat' => '26.8513006', 'lon' => '81.0035153']], 200),
        ]);
        $organization = $this->organization();

        $this->actingAs($this->admin, 'platform')
            ->putJson("/api/v1/admin/organizations/{$organization->uuid}", $this->updatePayload($organization, [
                'address' => 'Gomti Nagar',
                'postal_code' => '226010',
                'city' => 'Lucknow',
                'state_province' => 'Uttar Pradesh',
            ]))
            ->assertOk();

        $profile = $organization->profile()->first();
        $this->assertEqualsWithDelta(26.8513006, $profile->latitude, 0.000001);
        $this->assertSame('address', $profile->location_source);

        Http::assertSent(fn (Request $request) => str_contains($request->data()['q'] ?? '', 'Gomti Nagar'));
    }

    public function test_the_admin_lookups_answer_for_the_organization_form(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/search*' => Http::response([['lat' => '26.85', 'lon' => '81.00']], 200),
            'api.postalpincode.in/*' => Http::response([[
                'Status' => 'Success',
                'PostOffice' => [['Name' => 'Gomti Nagar', 'District' => 'Lucknow', 'State' => 'Uttar Pradesh', 'Country' => 'India']],
            ]], 200),
        ]);

        $this->getJson('/api/v1/admin/lookups/geocode?pincode=226010')->assertUnauthorized();

        $this->actingAs($this->admin, 'platform')
            ->getJson('/api/v1/admin/lookups/pincode/226010')
            ->assertOk()
            ->assertJsonPath('data.state', 'Uttar Pradesh')
            ->assertJsonPath('data.district', 'Lucknow');

        $this->actingAs($this->admin, 'platform')
            ->getJson('/api/v1/admin/lookups/geocode?pincode=226010')
            ->assertOk()
            ->assertJsonPath('data.source', 'pincode');
    }

    public function test_an_address_not_on_the_map_answers_404(): void
    {
        Http::fake(['nominatim.openstreetmap.org/search*' => Http::response([], 200)]);

        $this->actingAs($this->admin, 'platform')
            ->getJson('/api/v1/admin/lookups/geocode?city=Nowhereville')
            ->assertNotFound();
    }

    public function test_the_dashboard_places_only_located_organizations(): void
    {
        $located = $this->organization();
        $located->profile()->create([
            'city' => 'Lucknow', 'state_province' => 'Uttar Pradesh',
            'latitude' => 26.8467, 'longitude' => 80.9462, 'location_source' => 'manual',
        ]);

        // A state but no point: in the lists, not on the map.
        Organization::factory()->create()->profile()->create(['state_province' => 'Delhi']);

        $locations = $this->actingAs($this->admin, 'platform')
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->json('data.locations');

        $this->assertSame(1, $locations['located']);
        $this->assertCount(1, $locations['places']);
        $this->assertSame($located->uuid, $locations['places'][0]['uuid']);
        $this->assertSame('manual', $locations['places'][0]['source']);
        $this->assertCount(2, $locations['points']);
    }
}
