<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The SPA catch-all at the root.
 *
 * One route now answers every page in the application, which makes its
 * exclusion list easy to break by accident — a wrong prefix silently turns
 * the JSON API into HTML, and the SPA would look like it was returning
 * nonsense rather than 401s.
 */
class SpaRoutingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string}>
     */
    public static function spaPaths(): array
    {
        return [
            'root' => ['/'],
            'a feature page' => ['/organizations'],
            'a nested page' => ['/organizations/create'],
            'the invitation link' => ['/organization/setup/some-token'],
            'an unknown page, resolved client-side' => ['/nothing-here'],
        ];
    }

    #[DataProvider('spaPaths')]
    public function test_the_shell_is_served_for_app_paths(string $path): void
    {
        $this->get($path)
            ->assertOk()
            ->assertSee('id="root"', escape: false);
    }

    /**
     * The invitation mail sends the owner to /organization/setup/{token}.
     * While the SPA was mounted under /app this matched no route at all and
     * every invitation landed on a 404.
     */
    public function test_the_invitation_path_reaches_the_spa(): void
    {
        $this->get('/organization/setup/abc123')->assertOk();
    }

    public function test_the_api_is_not_swallowed_by_the_catch_all(): void
    {
        // JSON, not the HTML shell — and 401 rather than a redirect to a
        // login route that no longer exists.
        $this->getJson('/api/v1/admin/organizations')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_an_api_request_that_does_not_ask_for_json_still_gets_json(): void
    {
        // curl and server-to-server callers send no Accept header. They must
        // not be redirected into the SPA.
        $this->get('/api/v1/admin/organizations')->assertStatus(401);
    }

    public function test_reserved_prefixes_keep_their_own_routes(): void
    {
        $this->get('/up')->assertOk()->assertSee('Application up');
        $this->get('/sanctum/csrf-cookie')->assertNoContent();
    }

    public function test_a_path_merely_starting_with_a_reserved_word_is_still_the_spa(): void
    {
        // The exclusion is anchored to whole segments, so a future page at
        // /api-docs belongs to the SPA rather than 404ing.
        $this->get('/api-docs')->assertOk()->assertSee('id="root"', escape: false);
    }
}
