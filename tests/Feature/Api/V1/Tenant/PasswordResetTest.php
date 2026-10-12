<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Tenant\User as TenantUser;
use App\Notifications\Tenant\ResetPasswordNotification;
use App\Services\Tenancy\TenantConnectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TenantTestCase;

/**
 * Forgot/reset-password for a tenant's own staff — the first time this
 * codebase has ever had one, since the admin panel's own broker has nothing
 * to do with the per-organization `organization` connection this uses.
 */
class PasswordResetTest extends TenantTestCase
{
    use RefreshDatabase;

    private function ownerFor(\App\Models\Platform\Organization $organization): TenantUser
    {
        return $this->onTenant(
            $organization,
            fn () => TenantUser::on(TenantConnectionService::CONNECTION)
                ->where('email', $organization->email)
                ->firstOrFail(),
        );
    }

    public function test_a_reset_link_is_emailed_with_the_right_subdomain(): void
    {
        Notification::fake();

        $organization = $this->provisionOrganization('PW');

        $this->postJson('/api/v1/tenant/auth/forgot-password', [
            'subdomain' => $organization->subdomain,
            'email' => $organization->email,
        ])->assertOk();

        $user = $this->ownerFor($organization);

        Notification::assertSentTo(
            $user,
            ResetPasswordNotification::class,
            function (ResetPasswordNotification $notification) use ($user, $organization) {
                $url = $notification->toMail($user)->actionUrl;

                return str_contains($url, $organization->subdomain.'.hms.local/reset-password/');
            }
        );
    }

    public function test_the_response_does_not_reveal_whether_the_address_exists(): void
    {
        $organization = $this->provisionOrganization('PW');

        $known = $this->postJson('/api/v1/tenant/auth/forgot-password', [
            'subdomain' => $organization->subdomain,
            'email' => $organization->email,
        ]);

        $unknown = $this->postJson('/api/v1/tenant/auth/forgot-password', [
            'subdomain' => $organization->subdomain,
            'email' => 'nobody@example.com',
        ]);

        $known->assertOk();
        $unknown->assertOk();
        $this->assertSame($known->json('message'), $unknown->json('message'));
    }

    public function test_an_unknown_subdomain_is_rejected(): void
    {
        $this->postJson('/api/v1/tenant/auth/forgot-password', [
            'subdomain' => 'no-such-clinic-'.uniqid(),
            'email' => 'owner@example.com',
        ])->assertStatus(422);
    }

    public function test_resetting_with_the_emailed_token_changes_the_password(): void
    {
        Notification::fake();

        $organization = $this->provisionOrganization('PW');

        $this->postJson('/api/v1/tenant/auth/forgot-password', [
            'subdomain' => $organization->subdomain,
            'email' => $organization->email,
        ])->assertOk();

        $user = $this->ownerFor($organization);
        $token = null;

        Notification::assertSentTo(
            $user,
            ResetPasswordNotification::class,
            function (ResetPasswordNotification $notification) use (&$token) {
                $token = $notification->token();

                return true;
            }
        );

        $this->assertNotNull($token);

        $this->postJson('/api/v1/tenant/auth/reset-password', [
            'subdomain' => $organization->subdomain,
            'token' => $token,
            'email' => $organization->email,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertOk();

        // The reset is only proven once the new password actually signs in.
        $this->postJson('/api/v1/tenant/auth/login', [
            'subdomain' => $organization->subdomain,
            'email' => $organization->email,
            'password' => 'brand-new-password',
        ])->assertOk();
    }

    public function test_an_invalid_token_is_rejected(): void
    {
        $organization = $this->provisionOrganization('PW');

        $this->postJson('/api/v1/tenant/auth/reset-password', [
            'subdomain' => $organization->subdomain,
            'token' => 'not-a-real-token',
            'email' => $organization->email,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertStatus(422);
    }

    public function test_a_token_issued_for_one_organization_cannot_reset_another(): void
    {
        Notification::fake();

        $first = $this->provisionOrganization('PW');
        $second = $this->provisionOrganization('PW');

        $this->postJson('/api/v1/tenant/auth/forgot-password', [
            'subdomain' => $first->subdomain,
            'email' => $first->email,
        ])->assertOk();

        $user = $this->ownerFor($first);
        $token = null;

        Notification::assertSentTo(
            $user,
            ResetPasswordNotification::class,
            function (ResetPasswordNotification $notification) use (&$token) {
                $token = $notification->token();

                return true;
            }
        );

        // Same token, same email, but presented against the SECOND
        // organization's subdomain — its own password_reset_tokens table
        // has never heard of this token.
        $this->postJson('/api/v1/tenant/auth/reset-password', [
            'subdomain' => $second->subdomain,
            'token' => $token,
            'email' => $first->email,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertStatus(422);
    }
}
