<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\Api\V1\Platform\UpdateOrganizationModulesRequest;
use App\Models\Platform\Module;
use App\Models\Platform\Organization;
use App\Services\Modules\ModuleAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Which modules a super administrator has sold an organization.
 *
 * This is the outer half of the permission model. It decides what an
 * organization is *able* to do; who inside it may do each thing is the
 * organization's own business, and will be answered by roles built on the
 * capability pool these entitlements produce.
 */
class OrganizationModuleController extends BaseApiController
{
    public function __construct(
        private readonly ModuleAccess $access,
    ) {}

    /**
     * The catalogue, with this organization's standing against each row.
     *
     * One response rather than "list modules" plus "list bindings", because
     * the screen needs them joined and joining them in the browser is how
     * the two drift apart.
     */
    public function index(Organization $organization): JsonResponse
    {
        return $this->ok([
            'modules' => $this->access->overview($organization),

            // What the organization could grant its own roles today. Nothing
            // reads it yet; it is here so the shape is settled before the
            // role screen is written against it.
            'capabilities' => $this->access->capabilities($organization),
        ]);
    }

    public function update(
        UpdateOrganizationModulesRequest $request,
        Organization $organization,
    ): JsonResponse {
        $submitted = collect($request->validated('modules'))->keyBy('key');

        $ids = Module::whereIn('key', $submitted->keys())->pluck('id', 'key');

        DB::transaction(function () use ($organization, $submitted, $ids) {
            foreach ($submitted as $key => $binding) {
                $organization->moduleEntitlements()->updateOrCreate(
                    ['module_id' => $ids[$key]],
                    [
                        'is_enabled' => $binding['is_enabled'],
                        'starts_at' => $binding['starts_at'] ?? null,
                        'expires_at' => $binding['expires_at'] ?? null,
                        'note' => $binding['note'] ?? null,
                    ]
                );
            }

            /*
             * Anything the screen did not send is no longer part of the
             * arrangement. Deleted rather than disabled: a row left behind
             * would carry stale dates that reappear the moment somebody
             * re-ticks the module.
             */
            $organization->moduleEntitlements()
                ->whereNotIn('module_id', $ids->values())
                ->delete();
        });

        return $this->ok([
            'modules' => $this->access->overview($organization->fresh()),
            'capabilities' => $this->access->capabilities($organization->fresh()),
        ], 'Modules updated');
    }
}
