<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Tenant\LocationModule;
use App\Models\Tenant\ModuleLock;
use App\Services\Permissions\Permission;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Which modules a branch may not switch off.
 *
 * Owner-only, on the route. `location_modules` lets each branch opt out of
 * what the organization holds; this is the organization saying a module is not
 * optional — billing runs at every site, or the numbers the organization
 * reports are made of nothing.
 *
 * Locking a module that some branch has already switched off turns it back on
 * there. The alternative — refusing until the owner visits each branch and
 * switches it on by hand — leaves the organization holding two contradictory
 * answers in the meantime, which is the state `location_modules` deletes rows
 * rather than storing `true` specifically to avoid. Every branch turned back
 * on is written to the activity log under its own name.
 */
class ModuleLockController extends BaseApiController
{
    public function __construct(
        private readonly Permission $permission,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $organization = $request->attributes->get('tenant.organization');

        $entitled = $organization ? $this->permission->modulesAt($organization, null) : [];
        $locked = ModuleLock::lockedKeys();

        $modules = [];

        foreach (ModuleRegistry::all() as $module) {
            /*
             * Core modules are not offered. They cannot be switched off at a
             * branch in the first place, so locking one would be a control
             * that changes nothing — and a control that changes nothing reads
             * as one that is broken.
             */
            if ($module['is_core'] || ! in_array($module['key'], $entitled, true)) {
                continue;
            }

            $modules[] = [
                'key' => $module['key'],
                'name' => $module['name'],
                'description' => $module['description'],
                'icon' => $module['icon'],
                'group' => $module['group'],
                'is_locked' => in_array($module['key'], $locked, true),
            ];
        }

        return $this->ok(['modules' => $modules]);
    }

    /**
     * Replace the list of compulsory modules in one write.
     */
    public function update(Request $request): JsonResponse
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

        $locked = array_values(array_unique($validated['modules']));

        DB::connection('organization')->transaction(function () use ($locked, $request) {
            ModuleLock::query()->whereNotIn('module_key', $locked ?: ['-'])->delete();

            foreach ($locked as $key) {
                ModuleLock::firstOrCreate(
                    ['module_key' => $key],
                    ['created_by' => $request->user()?->getKey()],
                );
            }

            $this->switchBackOn($locked);
        });

        // The branches that just changed were read before this write.
        $this->permission->forget();

        return $this->show($request);
    }

    /**
     * Turn a newly compulsory module back on wherever it was off.
     *
     * @param  list<string>  $locked
     */
    private function switchBackOn(array $locked): void
    {
        if ($locked === []) {
            return;
        }

        $conflicts = LocationModule::query()
            ->whereIn('module_key', $locked)
            ->get();

        foreach ($conflicts->groupBy('location_id') as $locationId => $rows) {
            $was = LocationModule::query()
                ->where('location_id', $locationId)
                ->pluck('module_key')
                ->all();

            LocationModule::query()
                ->where('location_id', $locationId)
                ->whereIn('module_key', $locked)
                ->delete();

            $branch = $rows->first()->location;

            // Written against the branch that lost the choice, under its own
            // name, exactly as its own module screen writes.
            $branch?->writeHistoryFor(
                'modules_off',
                $was,
                array_values(array_diff($was, $locked)),
            );
        }
    }
}
