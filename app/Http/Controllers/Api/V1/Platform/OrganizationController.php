<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Concerns\HandlesTableQueries;
use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\Api\V1\Platform\StoreOrganizationRequest;
use App\Http\Requests\Api\V1\Platform\UpdateOrganizationRequest;
use App\Http\Resources\Platform\OrganizationResource;
use App\Models\Platform\Organization;
use App\Repositories\Platform\Contracts\OrganizationRepositoryInterface;
use App\Services\Geo\OrganizationLocator;
use App\Services\Platform\OrganizationProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * Organizations — Build Spec §19.
 *
 * Route model binding resolves on the ULID (see Organization::getRouteKeyName),
 * so every path here is /organizations/{uuid}.
 *
 * @group Platform > Organizations
 */
class OrganizationController extends BaseApiController
{
    use HandlesTableQueries;

    public function __construct(
        private readonly OrganizationRepositoryInterface $organizations,
        private readonly OrganizationProvisioningService $provisioning,
        private readonly OrganizationLocator $locator,
    ) {}

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
                $organization->load(['organizationType', 'tenantDatabase.dbCluster', 'migrationState', 'profile'])
            )
        );
    }

    public function store(StoreOrganizationRequest $request): JsonResponse
    {
        $data = $request->validatedForCreation();

        try {
            $organization = $this->provisioning->provision(
                Arr::except($data, OrganizationLocator::FIELDS),
                $request->file('profile_image'),
            );
        } catch (\Throwable $e) {
            return $this->failFromThrowable($e, 'Failed to create organization.');
        }

        // The address detail and map pin live on the profile, not the row.
        $this->locator->save($organization, Arr::only($data, OrganizationLocator::FIELDS));

        /*
         * Created, and being built. The database, schema and owner login are
         * made on the `provisioning` queue; the organization's page follows
         * them through progress() below.
         */
        return $this->created(
            OrganizationResource::make($organization),
            $organization->status === Organization::ACTIVE
                ? 'Organization created successfully.'
                : 'Organization created. Setting it up in the background.'
        );
    }

    /**
     * How far setting the organization up has got — polled by its page
     * while the queue works: each step, and the migrations as they run.
     */
    public function provisioning(Organization $organization): JsonResponse
    {
        return $this->ok($this->provisioning->progress($organization));
    }

    public function update(
        UpdateOrganizationRequest $request,
        Organization $organization
    ): JsonResponse {
        $data = $request->validatedForUpdate();

        $this->locator->save($organization, Arr::only($data, OrganizationLocator::FIELDS));
        $data = Arr::except($data, OrganizationLocator::FIELDS);

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
            OrganizationResource::make($organization->load(['organizationType', 'profile'])),
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
            $organization->status === Organization::ACTIVE
                ? 'Provisioning retried successfully.'
                : 'Provisioning queued again. It resumes from the step that failed.'
        );
    }
}
