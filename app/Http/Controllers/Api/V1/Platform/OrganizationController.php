<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Concerns\HandlesTableQueries;
use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\Api\V1\Platform\StoreOrganizationRequest;
use App\Http\Requests\Api\V1\Platform\UpdateOrganizationRequest;
use App\Http\Resources\Platform\OrganizationResource;
use App\Models\Platform\Organization;
use App\Repositories\Platform\Contracts\OrganizationRepositoryInterface;
use App\Services\Platform\OrganizationProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Organizations — Build Spec §19.
 *
 * Route model binding resolves on the ULID (see Organization::getRouteKeyName),
 * so every path here is /organizations/{uuid}.
 */
class OrganizationController extends BaseApiController
{
    use HandlesTableQueries;

    public function __construct(
        private readonly OrganizationRepositoryInterface $organizations,
        private readonly OrganizationProvisioningService $provisioning,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->organizations->listing([
            'status' => $request->string('status')->toString() ?: null,
            'organization_type_id' => $request->filled('organization_type_id')
                ? $request->integer('organization_type_id')
                : null,
            'is_active' => $request->filled('is_active')
                ? $request->boolean('is_active')
                : null,
        ]);

        return $this->paginated(
            $this->tableQuery(
                $query,
                $request,
                searchable: [
                    'organization_name',
                    'organization_code',
                    'slug',
                    'email',
                    'subdomain',
                    'contact_person_name',
                ],
                sortable: [
                    'organization_name',
                    'organization_code',
                    'email',
                    'subdomain',
                    'status',
                    'is_active',
                    'created_at',
                ],
                defaultSort: 'created_at',
            ),
            OrganizationResource::class,
        );
    }

    public function show(Organization $organization): JsonResponse
    {
        return $this->ok(
            OrganizationResource::make(
                $organization->load(['organizationType', 'tenantDatabase.dbCluster', 'migrationState'])
            )
        );
    }

    public function store(StoreOrganizationRequest $request): JsonResponse
    {
        try {
            $organization = $this->provisioning->provision(
                $request->validatedForCreation(),
                $request->file('profile_image'),
            );
        } catch (\Throwable $e) {
            return $this->failFromThrowable($e, 'Failed to create organization.');
        }

        return $this->created(
            OrganizationResource::make($organization),
            'Organization created successfully.'
        );
    }

    public function update(
        UpdateOrganizationRequest $request,
        Organization $organization
    ): JsonResponse {
        $data = $request->validatedForUpdate();

        if ($request->hasFile('profile_image')) {
            $previousImageId = $organization->profile_image;

            $data['profile_image'] = uploadFile($request->file('profile_image'), 'organizations');

            // Only drop the old logo once the replacement is safely stored.
            if ($previousImageId) {
                deleteFile($previousImageId);
            }
        }

        $organization = $this->organizations->update($organization, $data);

        return $this->ok(
            OrganizationResource::make($organization->load('organizationType')),
            'Organization updated successfully.'
        );
    }

    /**
     * Soft delete. The tenant database is intentionally left in place so the
     * organization can be restored; dropping it is the hard delete of §9,
     * which needs its own confirmation and an export first.
     */
    public function destroy(Organization $organization): JsonResponse
    {
        $this->organizations->delete($organization);

        return $this->noContent('Organization deleted successfully.');
    }

    /**
     * Resume a provisioning attempt that stopped short — steps already
     * completed are skipped, only what's left runs.
     */
    public function retryProvisioning(Organization $organization): JsonResponse
    {
        try {
            $organization = $this->provisioning->retryProvisioning($organization);
        } catch (\Throwable $e) {
            return $this->failFromThrowable($e, 'Failed to retry provisioning.');
        }

        return $this->ok(
            OrganizationResource::make($organization),
            'Provisioning retried successfully.'
        );
    }
}
