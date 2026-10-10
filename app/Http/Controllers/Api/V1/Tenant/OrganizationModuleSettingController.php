<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Tenant\ModuleLock;
use App\Models\Tenant\OrganizationModuleSetting;
use App\Services\Modules\FeatureResolver;
use App\Services\Permissions\Permission;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Which of the modules it was sold the organisation actually uses.
 *
 * Owner-only, on the route, beside the branch screen and the locks — the same
 * reasoning applies: a role able to switch a module on could re-open a door
 * the owner closed.
 *
 * Between the super admin's sale and each branch's opt-out. Switching a module
 * off here switches it off at EVERY branch, including any branch that had it
 * deliberately on, and including branches opened afterwards — which is the
 * thing the branch screen alone could not say.
 *
 * Only ever narrows. A module the organisation was not sold is not offered,
 * and nothing written here can make one run.
 *
 * @group Tenant > Module Settings
 */
class OrganizationModuleSettingController extends BaseApiController
{
    public function __construct(
        private readonly Permission $permission,
        private readonly FeatureResolver $features,
    ) {}

    /** The sold, switchable modules, each with the organisation's answer. */
    public function show(Request $request): JsonResponse
    {
        $organization = $request->attributes->get('tenant.organization');
        $locked = ModuleLock::lockedKeys();

        $modules = [];

        foreach ($organization ? $this->features->explain($organization) : [] as $row) {
            // Core modules are every organisation's, and unsold ones are not
            // this organisation's to decide about.
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
                'is_enabled' => $row['organization_enabled'],
                'is_locked' => in_array($row['key'], $locked, true),
                'requires' => ModuleRegistry::requires($row['key']),
            ];
        }

        return $this->ok(['modules' => $modules]);
    }

    /**
     * Switch modules on or off for the whole organisation.
     *
     * `{"modules": {"pharmacy": false}}` — only the modules named change, so a
     * client that knows about two modules cannot switch off a third it has
     * never heard of by leaving it out.
     */
    public function update(Request $request): JsonResponse
    {
        $organization = $request->attributes->get('tenant.organization');

        $request->validate([
            'modules' => ['required', 'array'],
            'modules.*' => ['required', 'boolean'],
        ]);

        $rows = collect($organization ? $this->features->explain($organization) : [])->keyBy('key');
        $locked = ModuleLock::lockedKeys();

        $changes = [];
        $errors = [];

        foreach ((array) $request->input('modules') as $key => $value) {
            $key = (string) $key;
            $on = (bool) $value;
            $row = $rows->get($key);
            $field = "modules.{$key}";

            if ($row === null || ! $row['purchased']) {
                $errors[$field] = 'Your organization does not have that module.';

                continue;
            }

            if ($row['is_core']) {
                $errors[$field] = $this->name($key).' is part of every organisation and cannot be switched off.';

                continue;
            }

            /*
             * A lock says "on at every branch". Switching the module off for
             * the organisation while it stands would leave two answers that
             * cannot both be true, so the lock has to go first — on its own
             * screen, as its own decision.
             */
            if (! $on && in_array($key, $locked, true)) {
                $errors[$field] = $this->name($key).' is locked on at every branch. Unlock it before switching it off for the organisation.';

                continue;
            }

            $changes[$key] = $on;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        /*
         * The rule the platform binding and the branch screen apply, at this
         * level too: the organisation cannot run the pharmacy with medicines
         * switched off.
         */
        $after = [];

        foreach ($rows as $key => $row) {
            if (! $row['is_core'] && $row['purchased'] && ($changes[$key] ?? $row['organization_enabled'])) {
                $after[] = $key;
            }
        }

        $unmet = ModuleRegistry::unmetRequirements($after);

        if ($unmet !== []) {
            throw ValidationException::withMessages([
                'modules' => ModuleRegistry::describeUnmet($unmet),
            ]);
        }

        DB::connection('organization')->transaction(function () use ($changes, $request) {
            foreach ($changes as $key => $on) {
                if ($on) {
                    /*
                     * Deleted one model at a time rather than by query, so the
                     * row's own history records the module coming back — a
                     * query delete would skip the model events and the log
                     * would only ever show it going.
                     */
                    OrganizationModuleSetting::query()
                        ->where('module_key', $key)
                        ->get()
                        ->each
                        ->delete();

                    continue;
                }

                OrganizationModuleSetting::firstOrCreate(
                    ['module_key' => $key],
                    ['is_enabled' => false, 'created_by' => $request->user()?->getKey()],
                );
            }
        });

        // Everything worked out before this write is now wrong.
        $this->permission->forget();

        return $this->show($request);
    }

    private function name(string $key): string
    {
        return ModuleRegistry::find($key)['name'] ?? $key;
    }
}
