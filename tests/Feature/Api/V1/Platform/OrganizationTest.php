<?php

namespace Tests\Feature\Api\V1\Platform;

use App\Models\Platform\Organization;
use App\Models\Platform\PlatformRole;
use App\Models\Platform\PlatformUser;
use App\Repositories\Platform\Contracts\OrganizationRepositoryInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Organizations after the move to Build Spec §5.
 *
 * Creation is not exercised here — provisioning issues a real CREATE DATABASE
 * and belongs with the idempotency work in step 4. These cover the identity,
 * the read and write paths, and the lifecycle bookkeeping.
 */
class OrganizationTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): self
    {
        $admin = PlatformUser::factory()->withRole(PlatformRole::SUPER_ADMIN)->create();

        return $this->actingAs($admin, 'platform');
    }

    /*
    |--------------------------------------------------------------------------
    | Identity — §5
    |--------------------------------------------------------------------------
    */

    public function test_a_new_organization_gets_a_ulid_without_being_asked(): void
    {
        $organization = Organization::factory()->create(['uuid' => null]);

        $this->assertNotNull($organization->uuid);
        $this->assertSame(26, strlen($organization->uuid));
    }

    public function test_the_list_exposes_the_ulid_and_never_the_row_id(): void
    {
        $organization = Organization::factory()->create();

        $this->actingAsAdmin()
            ->getJson('/api/v1/admin/organizations')
            ->assertOk()
            ->assertJsonPath('data.0.uuid', $organization->uuid)
            ->assertJsonMissingPath('data.0.id');
    }

    public function test_an_organization_is_addressed_by_its_ulid(): void
    {
        $organization = Organization::factory()->create();

        $this->actingAsAdmin()
            ->getJson("/api/v1/admin/organizations/{$organization->uuid}")
            ->assertOk()
            ->assertJsonPath('data.slug', $organization->slug);
    }

    public function test_the_row_id_is_not_a_usable_address(): void
    {
        $organization = Organization::factory()->create();

        $this->actingAsAdmin()
            ->getJson("/api/v1/admin/organizations/{$organization->id}")
            ->assertStatus(404);
    }

    public function test_slugs_do_not_collide(): void
    {
        Organization::factory()->create(['slug' => 'city-pharmacy']);

        $this->assertSame('city-pharmacy-1', Organization::generateSlug('City Pharmacy'));
    }

    /*
    |--------------------------------------------------------------------------
    | Writes
    |--------------------------------------------------------------------------
    */

    public function test_an_admin_can_update_an_organization(): void
    {
        $organization = Organization::factory()->create();

        $this->actingAsAdmin()
            ->putJson("/api/v1/admin/organizations/{$organization->uuid}", [
                'organization_name' => 'Renamed Pharmacy',
                'organization_code' => $organization->organization_code,
                'organization_type_id' => $this->aTypeId(),
                'subdomain' => $organization->subdomain,
                'email' => $organization->email,
                'gstin' => '29ABCDE1234F1Z5',
                'timezone' => 'Asia/Kolkata',
                'currency' => 'inr',
                'country' => 'in',
            ])
            ->assertOk()
            ->assertJsonPath('data.organization_name', 'Renamed Pharmacy')
            ->assertJsonPath('data.gstin', '29ABCDE1234F1Z5')
            // Normalised on the way in.
            ->assertJsonPath('data.currency', 'INR')
            ->assertJsonPath('data.country', 'IN');
    }

    public function test_updating_cannot_move_the_tenant_database_or_the_slug(): void
    {
        $organization = Organization::factory()->create([
            'slug' => 'keep-this',
            'database_name' => 'hms_keep_this',
        ]);

        $this->actingAsAdmin()
            ->putJson("/api/v1/admin/organizations/{$organization->uuid}", [
                'organization_name' => $organization->organization_name,
                'organization_code' => $organization->organization_code,
                'organization_type_id' => $this->aTypeId(),
                'subdomain' => $organization->subdomain,
                'email' => $organization->email,

                // Both sent deliberately; both must be ignored.
                'slug' => 'hijacked',
                'database_name' => 'hms_hijacked',
            ])
            ->assertOk();

        $organization->refresh();

        $this->assertSame('keep-this', $organization->slug);
        $this->assertSame('hms_keep_this', $organization->database_name);
    }

    public function test_deleting_is_a_soft_delete_so_the_tenant_database_survives(): void
    {
        $organization = Organization::factory()->create();

        $this->actingAsAdmin()
            ->deleteJson("/api/v1/admin/organizations/{$organization->uuid}")
            ->assertOk();

        $this->assertSoftDeleted('organizations', ['id' => $organization->id]);
    }

    /*
    |--------------------------------------------------------------------------
    | Lifecycle — §9
    |--------------------------------------------------------------------------
    */

    public function test_a_status_change_writes_its_own_history(): void
    {
        $organization = Organization::factory()->create();
        $admin = PlatformUser::factory()->create();

        $repository = app(OrganizationRepositoryInterface::class);

        $repository->changeStatus(
            $organization,
            Organization::SUSPENDED,
            'Non-payment',
            $admin->id
        );

        $this->assertDatabaseHas('organization_status_history', [
            'organization_id' => $organization->id,
            'from_status' => Organization::PENDING,
            'to_status' => Organization::SUSPENDED,
            'reason' => 'Non-payment',
            'changed_by' => $admin->id,
        ]);
    }

    public function test_suspending_stamps_the_time_and_the_reason(): void
    {
        $organization = Organization::factory()->active()->create();

        app(OrganizationRepositoryInterface::class)->changeStatus(
            $organization,
            Organization::SUSPENDED,
            'Non-payment'
        );

        $organization->refresh();

        $this->assertNotNull($organization->suspended_at);
        $this->assertSame('Non-payment', $organization->suspension_reason);
        $this->assertFalse($organization->canSignIn());
    }

    public function test_resuming_clears_the_suspension(): void
    {
        $organization = Organization::factory()->suspended()->create();

        app(OrganizationRepositoryInterface::class)->changeStatus(
            $organization,
            Organization::ACTIVE
        );

        $organization->refresh();

        $this->assertNull($organization->suspended_at);
        $this->assertNull($organization->suspension_reason);
        $this->assertNotNull($organization->activated_at);
        $this->assertTrue($organization->canSignIn());
    }

    public function test_moving_to_the_status_it_already_has_records_nothing(): void
    {
        $organization = Organization::factory()->active()->create();

        app(OrganizationRepositoryInterface::class)->changeStatus(
            $organization,
            Organization::ACTIVE
        );

        $this->assertDatabaseCount('organization_status_history', 0);
    }

    public function test_the_list_can_be_filtered_by_status(): void
    {
        Organization::factory()->active()->create();
        Organization::factory()->suspended()->create();

        $this->actingAsAdmin()
            ->getJson('/api/v1/admin/organizations?status='.Organization::SUSPENDED)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', Organization::SUSPENDED);
    }

    /** The update rules require a real organization type. */
    private function aTypeId(): int
    {
        return \App\Models\Platform\OrganizationType::create([
            'name' => 'Pharmacy '.uniqid(),
            'slug' => 'pharmacy-'.uniqid(),
            'is_active' => true,
        ])->id;
    }
}
