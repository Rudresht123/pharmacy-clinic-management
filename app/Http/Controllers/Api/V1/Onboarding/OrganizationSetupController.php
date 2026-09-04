<?php

namespace App\Http\Controllers\Api\V1\Onboarding;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\Api\V1\Onboarding\SetupOrganizationRequest;
use App\Models\Platform\Organization;
use App\Models\Tenant\User as TenantUser;
use App\Services\Notifications\EmailService;
use App\Services\Tenancy\TenantConnectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Public, unauthenticated: the flow an invited organization admin follows
 * from the emailed setup link.
 */
class OrganizationSetupController extends BaseApiController
{
    public function __construct(
        private readonly TenantConnectionService $tenants,
    ) {}

    /**
     * Validate the token and return just enough to render the setup form.
     */
    public function show(string $token): JsonResponse
    {
        $organization = $this->resolveToken($token);

        if ($organization instanceof JsonResponse) {
            return $organization;
        }

        return $this->ok([
            'organization_name' => $organization->organization_name,
            'email' => $organization->email,
            'contact_person_name' => $organization->contact_person_name,
        ]);
    }

    public function store(SetupOrganizationRequest $request, string $token): JsonResponse
    {
        $organization = $this->resolveToken($token);

        if ($organization instanceof JsonResponse) {
            return $organization;
        }

        try {
            $user = $this->tenants->run(
                $organization->database_name,
                fn () => TenantUser::on(TenantConnectionService::CONNECTION)->create([
                    'name' => $request->validated('admin_name'),
                    'email' => $organization->email,
                    'password' => Hash::make($request->validated('password')),
                    'is_active' => true,
                    'role' => TenantUser::OWNER,
                ])
            );

            /*
             * The status is not touched here. Provisioning already moved the
             * organization to active — §7 ends there — and this step only
             * records that the owner has chosen their password. Writing
             * 'active' again would add a meaningless row to the status
             * history, or worse, revive an organization an admin had since
             * suspended.
             */
            $organization->update([
                'is_setup_completed' => true,
                'setup_completed_at' => now(),
                'setup_token' => null,
                'setup_token_expires_at' => null,
            ]);

            $loginUrl = $this->tenantLoginUrl($organization, $request);

            EmailService::send(
                'organization_setup_completed',
                $organization->email,
                [
                    'user_name' => $user->name,
                    'email' => $user->email,
                    'organization_name' => $organization->organization_name,
                    'login_button' => emailButton($loginUrl, 'Login To Your Account'),
                ],
            );

            return $this->ok(
                [
                    'organization_name' => $organization->organization_name,
                    'email' => $user->email,
                    'login_url' => $loginUrl,
                ],
                'Account setup completed successfully.'
            );
        } catch (\Throwable $e) {
            return $this->failFromThrowable($e, 'Unable to complete account setup.');
        }
    }

    /**
     * @return Organization|JsonResponse The organization, or the error to return.
     */
    private function resolveToken(string $token): Organization|JsonResponse
    {
        $organization = Organization::where('setup_token', $token)->first();

        if (! $organization) {
            return $this->fail('This setup link is not valid.', 404);
        }

        if ($organization->is_setup_completed) {
            return $this->fail('This account has already been set up.', 410);
        }

        if (
            ! $organization->setup_token_expires_at
            || now()->greaterThan($organization->setup_token_expires_at)
        ) {
            return $this->fail('This setup link has expired.', 410);
        }

        return $organization;
    }

    private function tenantLoginUrl(Organization $organization, Request $request): string
    {
        $domain = config('organization.main_domain');

        if (! $domain) {
            return url('/login');
        }

        /*
         * The scheme follows whatever this request arrived on rather than a
         * hardcoded https://: local development serves these hosts over plain
         * http, where an https link simply fails to connect. The path is
         * explicit too — landing on / would bounce through /dashboard first.
         */
        return $request->getScheme().'://'
            .$organization->subdomain.'.'.$domain
            .'/login';
    }
}
