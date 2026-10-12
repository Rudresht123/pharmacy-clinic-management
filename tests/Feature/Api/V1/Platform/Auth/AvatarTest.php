<?php

namespace Tests\Feature\Api\V1\Platform\Auth;

use App\Models\File;
use App\Models\Platform\PlatformRole;
use App\Models\Platform\PlatformUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AvatarTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): PlatformUser
    {
        return PlatformUser::factory()->withRole(PlatformRole::SUPER_ADMIN)->create();
    }

    public function test_an_administrator_can_upload_a_profile_photo(): void
    {
        Storage::fake('public');

        $admin = $this->admin();

        $response = $this->actingAs($admin, 'platform')
            ->postJson('/api/v1/admin/auth/avatar', [
                'avatar' => UploadedFile::fake()->image('me.jpg'),
            ]);

        $response->assertOk();
        $this->assertNotNull($response->json('data.avatar_url'));

        $file = File::sole();
        Storage::disk('public')->assertExists($file->file_path);
    }

    public function test_replacing_a_photo_purges_the_previous_file(): void
    {
        Storage::fake('public');

        $admin = $this->admin();
        $actingAs = $this->actingAs($admin, 'platform');

        $actingAs->postJson('/api/v1/admin/auth/avatar', [
            'avatar' => UploadedFile::fake()->image('first.jpg'),
        ])->assertOk();

        $firstPath = File::sole()->file_path;

        $actingAs->postJson('/api/v1/admin/auth/avatar', [
            'avatar' => UploadedFile::fake()->image('second.jpg'),
        ])->assertOk();

        Storage::disk('public')->assertMissing($firstPath);
        $this->assertSame(1, File::count());
    }

    public function test_a_non_image_upload_is_rejected(): void
    {
        Storage::fake('public');

        $admin = $this->admin();

        $this->actingAs($admin, 'platform')
            ->postJson('/api/v1/admin/auth/avatar', [
                'avatar' => UploadedFile::fake()->create('report.pdf', 10, 'application/pdf'),
            ])
            ->assertStatus(422);

        $this->assertSame(0, File::count());
    }

    public function test_an_administrator_can_remove_their_photo(): void
    {
        Storage::fake('public');

        $admin = $this->admin();
        $actingAs = $this->actingAs($admin, 'platform');

        $actingAs->postJson('/api/v1/admin/auth/avatar', [
            'avatar' => UploadedFile::fake()->image('me.jpg'),
        ])->assertOk();

        $response = $actingAs->deleteJson('/api/v1/admin/auth/avatar');

        $response->assertOk();
        $this->assertNull($response->json('data.avatar_url'));
        $this->assertSame(0, File::count());
    }
}
