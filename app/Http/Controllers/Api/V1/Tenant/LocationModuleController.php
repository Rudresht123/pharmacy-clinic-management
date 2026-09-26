<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Tenant\Location;
use App\Models\Tenant\LocationModule;
use App\Models\Tenant\ModuleLock;
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

        // Sent so the screen can show a lock instead of a switch that would be
        // refused on save. The refusal is still the enforcement.
        $locked = ModuleLock::lockedKeys();

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
                'is_locked' => in_array($module['key'], $locked, true),
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
         * What the organization has made compulsory. Refused rather than
         * quietly forced back on: the branch asked for something it may not
         * have, and a screen that accepts a change and then shows the opposite
         * is worse than one that says why.
         */
        $locked = array_values(array_intersect($off, ModuleLock::lockedKeys()));

        if ($locked !== []) {
            throw ValidationException::withMessages([
                'modules' => ModuleRegistry::describeLocked($locked),
            ]);
        }

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

        /*
         * What this branch had switched off before the write, for the log
         * below. Read here because the transaction is about to replace it,
         * and the stored decision — what is OFF — is the stable one: the ON
         * list only means anything relative to what the organization happened
         * to hold at the moment somebody pressed save.
         */
        $wasOff = LocationModule::query()
            ->where('location_id', $location->id)
            ->pluck('module_key')
            ->all();

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

        /*
         * Against the branch, not against the rows: switching a module back on
         * DELETES its row, and a deleted row cannot carry its own history. The
         * branch is also what a reader is asking about — "when did Delhi lose
         * the pharmacy" is a question about Delhi.
         */
        $location->writeHistoryFor('modules_off', $wasOff, $off);

        // Otherwise the read below answers with the state from before the
        // write it just made.
        $this->permission->forget();

        return $this->show($request, $location);
    }
}
