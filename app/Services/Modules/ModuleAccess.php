<?php

namespace App\Services\Modules;

use App\Models\Platform\Module;
use App\Models\Platform\Organization;
use App\Models\Platform\OrganizationModule;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Support\Collection;

/**
 * What an organization can actually use, right now.
 *
 * The one place that turns bindings into an answer. Everything else — the
 * tenant sidebar, route middleware, the eventual role screen — asks this
 * rather than reading `organization_modules` and re-deciding what "enabled"
 * means, because "enabled" is three conditions and getting one of them wrong
 * in one place is how an expired subscription keeps working.
 */
class ModuleAccess
{
    /**
     * The module keys this organization may use.
     *
     * Core modules are always in the list and never have a row: every
     * organization has them by virtue of being one.
     *
     * @return list<string>
     */
    public function enabled(Organization $organization): array
    {
        $bound = $this->liveEntitlements($organization)
            ->map(fn (OrganizationModule $entitlement) => $entitlement->module?->key)
            ->filter()
            ->all();

        $keys = array_values(array_unique([...ModuleRegistry::coreKeys(), ...$bound]));

        // Registry order, so a sidebar built from this is stable rather than
        // ordered by whenever somebody happened to buy each module.
        return array_values(array_intersect(ModuleRegistry::keys(), $keys));
    }

    public function has(Organization $organization, string $moduleKey): bool
    {
        return in_array($moduleKey, $this->enabled($organization), true);
    }

    /**
     * Every permission this organization is in a position to grant.
     *
     * The pool the owner will distribute to their own roles. A capability
     * whose module is not entitled is not "denied to everyone" — it does not
     * exist for this organization, and must never appear on a role screen.
     *
     * @return list<string>
     */
    public function capabilities(Organization $organization): array
    {
        return ModuleRegistry::capabilitiesFor($this->enabled($organization));
    }

    /**
     * Whether a capability is reachable at all here.
     *
     * Route-level permissions will ask this before asking whether the
     * signed-in person's role has it — the two questions are different, and
     * an unentitled module has to fail first so the answer does not depend
     * on how roles happen to be configured.
     */
    public function grantsCapability(Organization $organization, string $capability): bool
    {
        $module = ModuleRegistry::moduleForCapability($capability);

        return $module !== null && $this->has($organization, $module);
    }

    /**
     * Bindings that are switched on, started and unexpired.
     *
     * @return Collection<int, OrganizationModule>
     */
    private function liveEntitlements(Organization $organization): Collection
    {
        return $organization->moduleEntitlements()
            ->with('module')
            ->get()
            ->filter(fn (OrganizationModule $entitlement) => $entitlement->isLive()
                && $entitlement->module?->is_active)
            ->values();
    }

    /**
     * The catalogue with each row's standing for this organization, for the
     * binding screen.
     *
     * @return list<array<string, mixed>>
     */
    public function overview(Organization $organization): array
    {
        $bindings = $organization->moduleEntitlements()->get()->keyBy('module_id');

        return Module::available()
            ->orderBy('sort_order')
            ->get()
            ->map(function (Module $module) use ($bindings) {
                $binding = $bindings->get($module->id);

                return [
                    'key' => $module->key,
                    'name' => $module->name,
                    'description' => $module->description,
                    'group' => $module->group,
                    'icon' => $module->icon,
                    'is_core' => $module->is_core,

                    // Core modules read as active with no binding behind them,
                    // which is exactly what they are.
                    'state' => $module->is_core ? 'core' : ($binding?->state() ?? 'unbound'),
                    'is_live' => $module->is_core || (bool) $binding?->isLive(),

                    'starts_at' => $binding?->starts_at?->toDateString(),
                    'expires_at' => $binding?->expires_at?->toDateString(),
                    'note' => $binding?->note,

                    'capabilities' => $module->capabilities(),
                ];
            })
            ->values()
            ->all();
    }
}
