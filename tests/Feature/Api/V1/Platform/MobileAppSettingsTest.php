<?php

namespace Tests\Feature\Api\V1\Platform;

use App\Models\Platform\PlatformRole;
use App\Models\Platform\PlatformSetting;
use App\Models\Platform\PlatformUser;
use App\Services\Google\ServiceAccountToken;
use App\Services\Platform\FirebaseRemoteConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The address development phones find the server at: typed by the platform
 * admin, published to Firebase Remote Config, read by the app on launch.
 */
class MobileAppSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/admin/mobile-app';

    private const REMOTE = 'https://firebaseremoteconfig.googleapis.com/v1/projects/zevro-test/remoteConfig';

    protected function setUp(): void
    {
        parent::setUp();

        // The service account's sign-in is its own concern; here it just
        // hands over a token, and Google's Remote Config itself is faked.
        $this->app->instance(FirebaseRemoteConfig::class, new FirebaseRemoteConfig(
            new class('zevro-test', []) extends ServiceAccountToken
            {
                public function for(string $scope): string
                {
                    return 'token-1';
                }
            },
        ));
    }

    private function asAdmin(): static
    {
        return $this->actingAs(PlatformUser::factory()->withRole(PlatformRole::SUPER_ADMIN)->create(), 'platform');
    }

    private function fakeGoogle(int $saveStatus = 200): void
    {
        Http::fake([
            self::REMOTE => function (Request $request) use ($saveStatus) {
                return $request->method() === 'GET'
                    ? Http::response([
                        'parameters' => ['welcome_text' => ['defaultValue' => ['value' => 'hi']]],
                        'version' => ['versionNumber' => '3'],
                    ], 200, ['ETag' => 'etag-3'])
                    : Http::response(
                        $saveStatus === 200 ? [] : ['error' => ['message' => 'Permission denied']],
                        $saveStatus,
                    );
            },
        ]);
    }

    public function test_the_address_is_saved_normalised_and_published_for_phones(): void
    {
        $this->fakeGoogle();

        $this->asAdmin()->putJson(self::URL, ['address' => ' 192.168.1.24:8000/ '])
            ->assertOk()
            ->assertJsonPath('data.address', 'http://192.168.1.24:8000')
            ->assertJsonPath('data.api_base', 'http://192.168.1.24:8000/api/v1')
            ->assertJsonPath('data.publish_error', null)
            ->assertJsonPath('message', 'Saved. Phones use it the next time the app opens.');

        // Written under the template's own ETag, keeping what was already there.
        Http::assertSent(function (Request $request) {
            if ($request->method() !== 'PUT') {
                return false;
            }

            $body = json_decode($request->body(), true);

            return $request->url() === self::REMOTE
                && $request->header('If-Match') === ['etag-3']
                && $body['parameters']['dev_api_base_url']['defaultValue']['value'] === 'http://192.168.1.24:8000/api/v1'
                && $body['parameters']['welcome_text']['defaultValue']['value'] === 'hi'
                && ! isset($body['version']);
        });

        $this->asAdmin()->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.address', 'http://192.168.1.24:8000')
            ->assertJsonStructure(['data' => ['published_at', 'suggestions']]);
    }

    public function test_an_address_a_phone_cannot_use_is_refused(): void
    {
        $this->fakeGoogle();

        $this->asAdmin()->putJson(self::URL, ['address' => 'localhost:8000'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('address');

        $this->asAdmin()->putJson(self::URL, ['address' => 'not an address'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('address');

        Http::assertNothingSent();
    }

    public function test_clearing_it_removes_it_from_firebase(): void
    {
        $this->fakeGoogle();

        $this->asAdmin()->putJson(self::URL, ['address' => ''])
            ->assertOk()
            ->assertJsonPath('data.address', null)
            ->assertJsonPath('message', 'Cleared. Phones will find the server on their own again.');

        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && ! array_key_exists('dev_api_base_url', json_decode($request->body(), true)['parameters']));
    }

    public function test_a_firebase_refusal_is_saved_and_said_rather_than_lost(): void
    {
        $this->fakeGoogle(saveStatus: 403);

        $this->asAdmin()->putJson(self::URL, ['address' => '10.0.0.5:8000'])
            ->assertOk()
            ->assertJsonPath('data.address', 'http://10.0.0.5:8000')
            ->assertJsonPath('data.published_at', null)
            ->assertJsonPath('data.publish_error', 'Firebase did not accept the change (403: Permission denied).');

        $this->assertSame('http://10.0.0.5:8000', PlatformSetting::forKey(PlatformSetting::MOBILE_SERVER)->value['address']);
    }

    public function test_only_the_platform_admin_can_see_or_change_it(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        $this->putJson(self::URL, ['address' => '10.0.0.5:8000'])->assertUnauthorized();
    }
}
