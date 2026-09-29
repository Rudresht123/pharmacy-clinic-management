<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\Api\V1\Tenant\UpdateSetupOrganizationRequest;
use App\Models\Platform\Organization;
use App\Models\Tenant\SetupStep;
use App\Services\Tenant\OrganizationSetup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The organisation setup screen's own endpoints.
 *
 * Only what no existing API does: where the setup stands, the organisation's
 * own details (until now only the platform could edit them), a section
 * signed off, and the setup finished. Branches, staff, roles, departments and
 * branch modules are saved through their existing endpoints. Owner-only, on
 * the route.
 *
 * Distinct from Onboarding\OrganizationSetupController, which is the public
 * invitation link where the owner first chooses a password.
 */
class SetupController extends BaseApiController
{
    public function __construct(
        private readonly OrganizationSetup $setup,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return $this->ok($this->payload($this->organization($request)));
    }

    /**
     * The organisation's details, on its own row, and its address detail on
     * the `organization_profiles` row the platform already keeps for it.
     */
    public function updateOrganization(UpdateSetupOrganizationRequest $request): JsonResponse
    {
        $organization = $this->organization($request);
        $data = $request->validated();

        DB::transaction(function () use ($organization, $data) {
            $organization->update(Arr::only($data, UpdateSetupOrganizationRequest::ORGANIZATION_FIELDS));
            $organization->profile()->updateOrCreate([], Arr::only($data, UpdateSetupOrganizationRequest::PROFILE_FIELDS));
        });

        return $this->ok($this->payload($organization), 'Organisation details saved');
    }

    /**
     * The organisation's letterhead mark — the one every branch prints unless
     * it has its own.
     *
     * OWNER-ONLY, on the route. It sits on the master `organizations` row,
     * which until now only the platform could touch; an owner setting up their
     * own clinic should not have to ask support to change their logo.
     *
     * SVG IS EXCLUDED DELIBERATELY. It is a document that can carry script,
     * and a letterhead needs no vectors.
     */
    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate([
            'logo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        $organization = $this->organization($request);
        $previous = $organization->profile_image;

        $organization->forceFill([
            'profile_image' => uploadFile($request->file('logo'), 'organizations'),
        ])->save();

        // Only once the replacement is stored — a failure above leaves the
        // organisation with the mark it had rather than with none.
        if ($previous) {
            deleteFile($previous);
        }

        return $this->ok($this->payload($organization), 'Logo updated.');
    }

    /** Drop it. Documents then print without a mark. */
    public function deleteLogo(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        $existing = $organization->profile_image;

        $organization->forceFill(['profile_image' => null])->save();

        if ($existing) {
            deleteFile($existing);
        }

        return $this->ok($this->payload($organization), 'Logo removed.');
    }

    /** Sign off a section: departments, roles or settings. */
    public function confirm(Request $request, string $step): JsonResponse
    {
        $this->setup->confirm($step);

        return $this->ok($this->payload($this->organization($request)), 'Section saved');
    }

    public function complete(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        $this->setup->complete($organization);

        return $this->ok($this->payload($organization), 'Organisation setup completed');
    }

    private function organization(Request $request): Organization
    {
        return $request->attributes->get('tenant.organization');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Organization $organization): array
    {
        $organization->load(['profile', 'organizationType']);

        $profile = $organization->profile;
        $signedOff = SetupStep::query()->max('updated_at');

        // When anything behind the setup last changed: the record, its profile, a sign-off.
        $lastUpdated = collect([$organization->updated_at, $profile?->updated_at, $signedOff ? Carbon::parse($signedOff) : null])
            ->filter()
            ->max();

        return [
            'organization' => [
                'name' => $organization->organization_name,
                'code' => $organization->organization_code,
                'type' => $organization->organizationType?->name,
                'legal_name' => $organization->legal_name,
                'contact_person_name' => $organization->contact_person_name,
                'email' => $organization->email,
                'phone_number' => $organization->phone_number,
                'address' => $organization->address,
                'gstin' => $organization->gstin,
                'drug_license_no' => $organization->drug_license_no,
                'website_url' => $profile?->website_url,
                'city' => $profile?->city,
                'state_province' => $profile?->state_province,
                'postal_code' => $profile?->postal_code,
            ],
            'last_updated' => $lastUpdated?->toIso8601String(),
            ...$this->setup->status($organization),
        ];
    }
}
