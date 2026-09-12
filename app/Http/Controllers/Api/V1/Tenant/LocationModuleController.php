<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Tenant\Location;
use App\Models\Tenant\LocationModule;
use App\Services\Permissions\Permission;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Which of the organization's modules a branch runs — level two of the flow.
 *
 * Owner-only. The super admin decides what the organization may use at all;
 * this decides where it is used, and only the owner can see the whole network
 * well enough to answer that.
 *
 * Core modules are not offered. A branch with no customers or no staff is not
 * a smaller branch, it is a broken one, which is the same reasoning that keeps
 * them off the platform's binding screen.
 */
class LocationModuleController extends BaseApiController
{
    public function __construct(
        private readonly Permission $permission,
    ) {}

    /**
     * The switchable modules for this branch, each with its standing.
     */
    public function show(Request $request, Location $location): JsonResponse
    {
        $organization = $request->attributes->get('tenant.organization');

        $entitled = $organization ? $this->permission->modulesAt($organization, null) : [];
        $running = $organization ? $this->permission->modulesAt($organization, $location->id) : [];

        $modules = [];

        foreach (ModuleRegistry::all() as $module) {
            if ($module['is_core'] || ! in_array($module['key'], $entitled, true)) {
                continue;
            }

            $modules[] = [
                'key' => $module['key'],
                'name' => $module['name'],
                'description' => $module['description'],
                'icon' => $module['icon'],
                'group' => $module['group'],
                'is_enabled' => in_array($module['key'], $running, true),
            ];
        }

        return $this->ok([
            'location_id' => $location->id,
            'modules' => $modules,
        ]);
    }

    /**
     * Replace this branch's decisions in one write.
     *
     * The body names the modules that ARE on here. What is stored is the
     * opposite — a row per module switched off — because the table is a sparse
     * override and a branch that has never been configured must go on
     * inheriting whatever the organization buys next.
     */
    public function update(Request $request, Location $location): JsonResponse
    {
        $organization = $request->attributes->get('tenant.organization');

        $switchable = array_values(array_filter(
            $organization ? $this->permission->modulesAt($organization, null) : [],
            fn (string $key) => ! in_array($key, ModuleRegistry::coreKeys(), true),
        ));

        $validated = $request->validate([
            'modules' => ['present', 'array'],
            'modules.*' => ['string', 'in:'.implode(',', $switchable ?: ['-'])],
        ], [
            'modules.*.in' => 'Your organization does not have that module.',
        ]);

        $on = $validated['modules'];
        $off = array_values(array_diff($switchable, $on));

        /*
         * The same rule the platform binding applies, one level down: a
         * branch cannot run prescriptions with medicines switched off there.
         */
        $unmet = ModuleRegistry::unmetRequirements($on);

        if ($unmet !== []) {
            throw ValidationException::withMessages([
                'modules' => ModuleRegistry::describeUnmet($unmet),
            ]);
        }

        DB::connection('organization')->transaction(function () use ($location, $on, $off) {
            /*
             * The rows for what is on are deleted rather than set to true.
             * Absence is what "inherited" means here, and leaving a true row
             * behind would freeze the branch at today's answer — a module
             * withdrawn from the organization would still read as switched on
             * at this branch, which is a contradiction nothing else can resolve.
             */
            LocationModule::query()
                ->where('location_id', $location->id)
                ->whereIn('module_key', $on ?: ['-'])
                ->delete();

            foreach ($off as $key) {
                LocationModule::updateOrCreate(
                    ['location_id' => $location->id, 'module_key' => $key],
                    ['is_enabled' => false],
                );
            }
        });

        // Otherwise the read below answers with the state from before the
        // write it just made.
        $this->permission->forget();

        return $this->show($request, $location);
    }
}
