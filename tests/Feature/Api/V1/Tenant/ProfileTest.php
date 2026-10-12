<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Tenant\File;
use App\Models\Tenant\User as TenantUser;
use App\Services\Tenancy\TenantConnectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TenantTestCase;

/** A signed-in tenant user's own account: profile, photo, and password. */
class ProfileTest extends TenantTestCase
{
    use RefreshDatabase;

    public function test_a_signed_in_owner_can_update_their_own_name_and_email(): void
    {
        $organization = $this->provisionOrganization('PR');
        $this->signInAsOwner($organization);

        $response = $this->putJson('/api/v1/tenant/auth/profile', [
            'name' => 'New Name',
            'email' => $organization->email,
        ]);

        $response->assertOk()->assertJsonPath('data.name', 'New Name');
    }

    public function test_the_new_email_must_not_already_belong_to_someone_else_at_the_same_organization(): void
    {
        $organization = $this->provisionOrganization('PR');
        $this->signInAsOwner($organization);

        $this->putJson('/api/v1/tenant/auth/profile', [
            'name' => 'Owner',
            'email' => self::STAFF_EMAIL,
        ])->assertStatus(422);
    }

    public function test_a_staff_member_can_upload_a_profile_photo(): void
    {
        Storage::fake('public');

        $organization = $this->provisionOrganization('PR');
        $this->signInAsStaff($organization);

        $response = $this->postJson('/api/v1/tenant/auth/avatar', [
            'avatar' => UploadedFile::fake()->image('me.jpg'),
        ]);

        $response->assertOk();
        $this->assertNotNull($response->json('data.avatar_url'));

        $this->onTenant($organization, function () {
            $file = File::on(TenantConnectionService::CONNECTION)->sole();
            Storage::disk('public')->assertExists($file->file_path);
        });
    }

    public function test_replacing_a_photo_purges_the_previous_file(): void
    {
        Storage::fake('public');

        $organization = $this->provisionOrganization('PR');
        $this->signInAsStaff($organization);

        $this->postJson('/api/v1/tenant/auth/avatar', [
            'avatar' => UploadedFile::fake()->image('first.jpg'),
        ])->assertOk();

        $firstPath = $this->onTenant(
            $organization,
            fn () => File::on(TenantConnectionService::CONNECTION)->sole()->file_path,
        );

        $this->postJson('/api/v1/tenant/auth/avatar', [
            'avatar' => UploadedFile::fake()->image('second.jpg'),
        ])->assertOk();

        Storage::disk('public')->assertMissing($firstPath);

        $this->onTenant($organization, function () {
            $this->assertSame(1, File::on(TenantConnectionService::CONNECTION)->count());
        });
    }

    public function test_a_staff_member_can_remove_their_photo(): void
    {
        Storage::fake('public');

        $organization = $this->provisionOrganization('PR');
        $this->signInAsStaff($organization);

        $this->postJson('/api/v1/tenant/auth/avatar', [
            'avatar' => UploadedFile::fake()->image('me.jpg'),
        ])->assertOk();

        $response = $this->deleteJson('/api/v1/tenant/auth/avatar');

        $response->assertOk();
        $this->assertNull($response->json('data.avatar_url'));

        $this->onTenant($organization, function () {
            $this->assertSame(0, File::on(TenantConnectionService::CONNECTION)->count());
        });
    }

    public function test_a_signed_in_staff_member_can_change_their_own_password(): void
    {
        $organization = $this->provisionOrganization('PR');
        $this->signInAsStaff($organization);

        $this->putJson('/api/v1/tenant/auth/password', [
            'current_password' => self::PASSWORD,
            'password' => 'a-new-password',
            'password_confirmation' => 'a-new-password',
        ])->assertOk();

        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/tenant/auth/login', [
            'subdomain' => $organization->subdomain,
            'email' => self::STAFF_EMAIL,
            'password' => 'a-new-password',
        ])->assertOk();
    }

    public function test_the_wrong_current_password_is_rejected(): void
    {
        $organization = $this->provisionOrganization('PR');
        $this->signInAsStaff($organization);

        $this->putJson('/api/v1/tenant/auth/password', [
            'current_password' => 'not-the-password',
            'password' => 'a-new-password',
            'password_confirmation' => 'a-new-password',
        ])->assertStatus(422);
    }

    public function test_profile_endpoints_reject_a_guest(): void
    {
        $this->putJson('/api/v1/tenant/auth/profile', ['name' => 'x', 'email' => 'x@example.com'])
            ->assertStatus(401);
    }
}
