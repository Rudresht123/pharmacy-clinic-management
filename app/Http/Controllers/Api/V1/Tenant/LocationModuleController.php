<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Platform\Organization;
use App\Models\Tenant\Location;
use App\Models\Tenant\LocationModule;
use App\Models\Tenant\ModuleLock;
use App\Services\Modules\FeatureResolver;
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
 *
 * TWO WAYS TO WRITE, ONE ANSWER.
 *
 *   `modules: [...]`     the switch screen's shape: the modules that ARE on
 *                        here, replacing everything. On means inherited, so
 *                        nothing `true` is ever stored this way.
 *   `overrides: {...}`   one decision per module — true, false, or null to go
 *                        back to inheriting — touching only what it names.
 *
 * Neither can make a module run here that the organization does not run: a
 * stored `true` is a record of a choice, and FeatureResolver never lets it
 * outrank the organization.
 */
class LocationModuleController extends BaseApiController
{
    public function __construct(
        private readonly Permission $permission,
        private readonly FeatureResolver $features,
    ) {}

    /**
     * The switchable modules for this branch, each with its standing.
     *
     * A module the organization has switched off for itself is still listed,
     * as off with `organization_enabled: false`, so the screen can say why
     * rather than the module silently vanishing from the branch.
     */
    public function show(Request $request, Location $location): JsonResponse
    {
        $organization = $request->attributes->get('tenant.organization');

        // Sent so the screen can show a lock instead of a switch that would be
        // refused on save. The refusal is still the enforcement.
        $locked = ModuleLock::lockedKeys();

        $modules = [];

        foreach ($this->rows($organization, $location) as $row) {
            if ($row['is_core'] || ! $row['purchased']) {
                continue;
            }

            $module = ModuleRegistry::find($row['key']);

            $modules[] = [
                'key' => $row['key'],
                'name' => $row['name'],
                'description' => $module['description'] ?? null,
                'icon' => $module['icon'] ?? null,
                'group' => $row['group'],
                'is_enabled' => $row['effective'],
                'is_locked' => in_array($row['key'], $locked, true),

                // What decided it: the organization's switch, this branch's
                // own choice (true, false, or null for inherited) and why.
                'organization_enabled' => $row['organization_enabled'],
                'override' => $row['branch_override'],
                'reason' => $row['reason'],
            ];
        }

        return $this->ok([
            'location_id' => $location->id,
            'modules' => $modules,
        ]);
    }

    public function update(Request $request, Location $location): JsonResponse
    {
        $organization = $request->attributes->get('tenant.organization');

        if ($request->has('overrides')) {
            $this->applyOverrides($request, $location, $organization);
        } else {
            $this->replaceRunning($request, $location, $organization);
        }

        // Otherwise the read below answers with the state from before the
        // write it just made.
        $this->permission->forget();

        return $this->show($request, $location);
    }

    /**
     * Replace this branch's decisions in one write — the switch screen.
     *
     * The body names the modules that ARE on here. What is stored is the
     * opposite — a row per module switched off — because the table is a sparse
     * override and a branch that has never been configured must go on
     * inheriting whatever the organization buys next.
     */
    private function replaceRunning(Request $request, Location $location, ?Organization $organization): void
    {
        $rows = $this->rows($organization, $location);

        // What the organization itself runs: the only things a branch can
        // switch between on and off.
        $switchable = $this->keysWhere($rows, fn (array $row) => ! $row['is_core'] && $row['organization_enabled']);
        $offForOrganization = $this->keysWhere($rows, fn (array $row) => ! $row['is_core'] && $row['purchased'] && ! $row['organization_enabled']);

        $validated = $request->validate([
            'modules' => ['present', 'array'],
            'modules.*' => ['string', function (string $attribute, mixed $value, \Closure $fail) use ($switchable, $offForOrganization) {
                // Not a string at all is the `string` rule's to report.
                if (! is_string($value) || in_array($value, $switchable, true)) {
                    return;
                }

                $fail(in_array($value, $offForOrganization, true)
                    ? $this->name($value).' is switched off for your organisation, so a branch cannot run it.'
                    : 'Your organization does not have that module.');
            }],
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
        $wasOff = $this->offAt($location);

        DB::connection('organization')->transaction(function () use ($location, $on, $off) {
            /*
             * The rows for what is on are deleted rather than set to true.
             * Absence is what "inherited" means here, and the switch screen
             * only ever says on or off — it has no way to say "on, and keep it
             * on whatever the organization does", so it must not store that.
             * A deliberate `true` is the `overrides` body's to write.
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
    }

    /**
     * Set, clear or flip individual modules — the three-state body.
     *
     *   true   on here deliberately. Never more than the organization allows:
     *          refused outright for a module the organization has switched
     *          off, and outranked by the organization if it switches one off
     *          later.
     *   false  off here, whatever the organization does.
     *   null   no opinion — inherit the organization's answer.
     *
     * Modules not named are left exactly as they were.
     */
    private function applyOverrides(Request $request, Location $location, ?Organization $organization): void
    {
        $request->validate([
            'overrides' => ['present', 'array'],
            'overrides.*' => ['nullable', 'boolean'],
        ]);

        $rows = collect($this->rows($organization, $location))->keyBy('key');
        $locked = ModuleLock::lockedKeys();

        $overrides = [];
        $errors = [];

        foreach ((array) $request->input('overrides') as $key => $value) {
            $key = (string) $key;
            $value = $value === null ? null : (bool) $value;
            $row = $rows->get($key);
            $field = "overrides.{$key}";

            if ($row === null) {
                $errors[$field] = 'There is no module called '.$key.'.';

                continue;
            }

            if ($row['is_core']) {
                $errors[$field] = $this->name($key).' runs at every branch and cannot be changed here.';

                continue;
            }

            /*
             * A branch cannot be given what the organization was never sold.
             * Clearing (null) is still allowed, so a choice left over from a
             * module since withdrawn can be tidied away.
             */
            if (! $row['purchased'] && $value !== null) {
                $errors[$field] = 'Your organization does not have that module.';

                continue;
            }

            if ($value === true && ! $row['organization_enabled']) {
                $errors[$field] = $this->name($key).' is switched off for your organisation, so a branch cannot switch it on.';

                continue;
            }

            if ($value === false && in_array($key, $locked, true)) {
                $errors[$field] = ModuleRegistry::describeLocked([$key]);

                continue;
            }

            $overrides[$key] = $value;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        // What would run here afterwards, checked as a whole: switching
        // medicines off while prescriptions inherits on is the same broken
        // branch whichever body asked for it.
        $after = $this->features->overridesAt($location->id);

        foreach ($overrides as $key => $value) {
            if ($value === null) {
                unset($after[$key]);
            } else {
                $after[$key] = $value;
            }
        }

        $on = $this->keysWhere(
            $rows->all(),
            fn (array $row) => ! $row['is_core'] && $row['organization_enabled'] && ($after[$row['key']] ?? null) !== false,
        );

        $unmet = ModuleRegistry::unmetRequirements($on);

        if ($unmet !== []) {
            throw ValidationException::withMessages([
                'overrides' => ModuleRegistry::describeUnmet($unmet),
            ]);
        }

        $wasOff = $this->offAt($location);
        $wasOn = $this->onAt($location);

        DB::connection('organization')->transaction(function () use ($location, $overrides) {
            foreach ($overrides as $key => $value) {
                if ($value === null) {
                    LocationModule::query()
                        ->where('location_id', $location->id)
                        ->where('module_key', $key)
                        ->delete();

                    continue;
                }

                LocationModule::updateOrCreate(
                    ['location_id' => $location->id, 'module_key' => $key],
                    ['is_enabled' => $value],
                );
            }
        });

        // Against the branch, for the reason replaceRunning() gives.
        $location->writeHistoryFor('modules_off', $wasOff, $this->offAt($location));
        $location->writeHistoryFor('modules_on', $wasOn, $this->onAt($location));
    }

    /** @return list<array<string, mixed>> */
    private function rows(?Organization $organization, Location $location): array
    {
        return $organization ? $this->features->explain($organization, $location->id) : [];
    }

    /**
     * @param  iterable<array<string, mixed>>  $rows
     * @return list<string>
     */
    private function keysWhere(iterable $rows, callable $keep): array
    {
        $keys = [];

        foreach ($rows as $row) {
            if ($keep($row)) {
                $keys[] = $row['key'];
            }
        }

        return $keys;
    }

    /**
     * What this branch has switched off. Only `false` rows: a deliberate
     * `true` is a row too, and counting it here would log it as "off".
     *
     * @return list<string>
     */
    private function offAt(Location $location): array
    {
        return array_keys(array_filter(
            $this->features->overridesAt($location->id),
            fn (bool $enabled) => ! $enabled,
        ));
    }

    /** @return list<string> */
    private function onAt(Location $location): array
    {
        return array_keys(array_filter($this->features->overridesAt($location->id)));
    }

    private function name(string $key): string
    {
        return ModuleRegistry::find($key)['name'] ?? $key;
    }
}
