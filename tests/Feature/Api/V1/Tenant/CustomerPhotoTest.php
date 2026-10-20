<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\Customer;
use App\Models\Tenant\File;
use App\Services\Tenancy\TenantConnectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TenantTestCase;

/** A patient's photograph: taken, replaced, removed — and a shared one kept. */
class CustomerPhotoTest extends TenantTestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->organization = $this->provisionOrganization('CP');
        $this->signInAsOwner($this->organization);
    }

    private function patient(string $name, string $phone): int
    {
        return $this->onTenant($this->organization, fn () => Customer::on(TenantConnectionService::CONNECTION)->create([
            'name' => $name, 'phone' => $phone, 'is_active' => true,
        ])->id);
    }

    public function test_a_photo_is_taken_and_shows_on_the_patient(): void
    {
        $id = $this->patient('Asha Rane', '9810000021');

        $this->postJson("/api/v1/tenant/customers/{$id}/photo", [
            'photo' => UploadedFile::fake()->image('asha.jpg'),
        ])->assertOk()->assertJsonPath('data.id', $id);

        $url = $this->getJson("/api/v1/tenant/customers/{$id}")->assertOk()->json('data.photo_url');
        $this->assertNotNull($url);

        $this->onTenant($this->organization, function () {
            Storage::disk('public')->assertExists(File::on(TenantConnectionService::CONNECTION)->sole()->file_path);
        });
    }

    public function test_replacing_a_photo_purges_the_previous_file(): void
    {
        $id = $this->patient('Asha Rane', '9810000022');

        $this->postJson("/api/v1/tenant/customers/{$id}/photo", [
            'photo' => UploadedFile::fake()->image('first.jpg'),
        ])->assertOk();

        $first = $this->onTenant($this->organization, fn () => File::on(TenantConnectionService::CONNECTION)->sole()->file_path);

        $this->postJson("/api/v1/tenant/customers/{$id}/photo", [
            'photo' => UploadedFile::fake()->image('second.jpg'),
        ])->assertOk();

        Storage::disk('public')->assertMissing($first);
        $this->onTenant($this->organization, fn () => $this->assertSame(1, File::on(TenantConnectionService::CONNECTION)->count()));
    }

    /** Demo portraits are shared; removing one patient's must not blank the others. */
    public function test_a_photo_another_patient_shows_is_kept_when_one_removes_it(): void
    {
        $first = $this->patient('Asha Rane', '9810000023');
        $second = $this->patient('Meera Rane', '9810000024');

        $this->postJson("/api/v1/tenant/customers/{$first}/photo", [
            'photo' => UploadedFile::fake()->image('shared.jpg'),
        ])->assertOk();

        $file = $this->onTenant($this->organization, function () use ($second) {
            $file = File::on(TenantConnectionService::CONNECTION)->sole();
            Customer::on(TenantConnectionService::CONNECTION)->whereKey($second)->update(['photo' => $file->id]);

            return $file;
        });

        $this->deleteJson("/api/v1/tenant/customers/{$first}/photo")
            ->assertOk()
            ->assertJsonPath('data.photo_url', null);

        Storage::disk('public')->assertExists($file->file_path);
        $this->assertNotNull($this->getJson("/api/v1/tenant/customers/{$second}")->json('data.photo_url'));
    }

    public function test_only_an_image_is_taken(): void
    {
        $id = $this->patient('Asha Rane', '9810000025');

        $this->postJson("/api/v1/tenant/customers/{$id}/photo", [
            'photo' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors('photo');
    }
}
