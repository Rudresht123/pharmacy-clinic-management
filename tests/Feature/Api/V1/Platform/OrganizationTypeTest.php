<?php

namespace Tests\Feature\Api\V1\Platform;

use App\Models\Platform\OrganizationType;
use App\Models\Platform\PlatformRole;
use App\Models\Platform\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Organization types CRUD.
 *
 * These exercise the whole request → FormRequest → controller → resource
 * path. A class that cannot be autoloaded, or a FormRequest whose namespace
 * does not match its folder, fails here rather than in the browser.
 */
class OrganizationTypeTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): self
    {
        $admin = PlatformUser::factory()->withRole(PlatformRole::SUPER_ADMIN)->create();

        return $this->actingAs($admin, 'platform');
    }

    public function test_an_admin_can_list_organization_types(): void
    {
        OrganizationType::create(['name' => 'Pharmacy', 'slug' => 'pharmacy', 'is_active' => true]);

        $this->actingAsAdmin()
            ->getJson('/api/v1/admin/organization-types')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Pharmacy');
    }

    public function test_an_admin_can_create_an_organization_type(): void
    {
        $this->actingAsAdmin()
            ->postJson('/api/v1/admin/organization-types', [
                'name' => 'Retail Chain',
                'is_active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'retail-chain');

        $this->assertDatabaseHas('organization_types', ['slug' => 'retail-chain']);
    }

    public function test_an_admin_can_update_an_organization_type(): void
    {
        $type = OrganizationType::create([
            'name' => 'Pharmacy',
            'slug' => 'pharmacy',
            'is_active' => true,
        ]);

        $this->actingAsAdmin()
            ->putJson("/api/v1/admin/organization-types/{$type->id}", [
                'name' => 'Community Pharmacy',
                'is_active' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Community Pharmacy');
    }

    public function test_updating_a_type_may_keep_its_own_name(): void
    {
        $type = OrganizationType::create([
            'name' => 'Pharmacy',
            'slug' => 'pharmacy',
            'is_active' => true,
        ]);

        // The unique rule has to ignore the row being edited, otherwise
        // saving a record without renaming it fails validation.
        $this->actingAsAdmin()
            ->putJson("/api/v1/admin/organization-types/{$type->id}", [
                'name' => 'Pharmacy',
                'is_active' => false,
            ])
            ->assertOk();
    }

    public function test_a_duplicate_name_is_rejected(): void
    {
        OrganizationType::create(['name' => 'Pharmacy', 'slug' => 'pharmacy', 'is_active' => true]);

        $this->actingAsAdmin()
            ->postJson('/api/v1/admin/organization-types', ['name' => 'Pharmacy'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_an_admin_can_toggle_status(): void
    {
        $type = OrganizationType::create([
            'name' => 'Pharmacy',
            'slug' => 'pharmacy',
            'is_active' => true,
        ]);

        $this->actingAsAdmin()
            ->patchJson("/api/v1/admin/organization-types/{$type->id}/toggle-status")
            ->assertOk();

        $this->assertFalse((bool) $type->fresh()->is_active);
    }

    public function test_an_admin_can_delete_an_organization_type(): void
    {
        $type = OrganizationType::create([
            'name' => 'Pharmacy',
            'slug' => 'pharmacy',
            'is_active' => true,
        ]);

        $this->actingAsAdmin()
            ->deleteJson("/api/v1/admin/organization-types/{$type->id}")
            ->assertOk();

        $this->assertSoftDeleted('organization_types', ['id' => $type->id]);
    }

    public function test_a_guest_cannot_reach_organization_types(): void
    {
        $this->getJson('/api/v1/admin/organization-types')->assertStatus(401);
    }
}
