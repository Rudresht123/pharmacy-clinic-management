<?php

namespace App\Services\Modules;

use App\Models\Platform\Module;
use App\Models\Platform\Organization;
use App\Models\Platform\OrganizationModule;
use App\Services\Subscriptions\SubscriptionFacts;
use App\Support\Modules\ModuleRegistry;

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
    public function __construct(
        private readonly ?SubscriptionFacts $subscriptions = null,
    ) {}

    /**
     * The module keys this organization may use.
     *
     * THE PRECEDENCE, first match wins — the one place it is decided:
     *
     *   1. Core modules: always on. They never have a row.
     *   2. (The subscription LOCK is not decided here. A locked organization
     *      is stopped by the `subscription` middleware before any module is
     *      asked about, whatever this list says.)
     *   3. A Super Admin's manual binding in `organization_modules`, while it
     *      is in force by its own dates — switched on is ON even when the plan
     *      does not include it (a complimentary grant); switched off is OFF
     *      even when the plan includes it (an explicit revoke).
     *   4. The subscription: its plan snapshot's modules plus bought module
     *      add-ons.
     *   5. Otherwise off.
     *
     * A module retired from the catalogue (`modules.is_active` false) is off
     * whatever grants it.
     *
     * An organization on Complimentary has no plan modules, so for it this is
     * exactly what it always was: core plus its live bindings.
     *
     * @return list<string>
     */
    public function enabled(Organization $organization): array
    {
        $keys = ModuleRegistry::coreKeys();
        $planned = $this->facts()->modules($organization);

        $bindings = $organization->moduleEntitlements()->with('module')->get()
            ->filter(fn (OrganizationModule $binding) => $binding->module !== null)
            ->keyBy(fn (OrganizationModule $binding) => $binding->module->key);

        $retired = Module::query()->where('is_active', false)->pluck('key')->all();

        foreach (ModuleRegistry::keys() as $key) {
            if (in_array($key, $keys, true) || in_array($key, $retired, true)) {
                continue;
            }

            /** @var OrganizationModule|null $binding */
            $binding = $bindings->get($key);

            $on = $binding !== null && $binding->inForce()
                ? $binding->is_enabled
                : in_array($key, $planned, true);

            if ($on) {
                $keys[] = $key;
            }
        }

        // Registry order, so a sidebar built from this is stable rather than
        // ordered by whenever somebody happened to buy each module.
        return array_values(array_intersect(ModuleRegistry::keys(), array_unique($keys)));
    }

    /**
     * Where each module's standing comes from, for the screens that explain
     * it: core, manual_grant, manual_revoke, plan, addon, or off.
     *
     * @return array<string, string>
     */
    public function sources(Organization $organization): array
    {
        $subscription = $this->facts()->subscription($organization);
        $snapshot = $subscription?->snapshotModules() ?? [];
        $planned = $this->facts()->modules($organization);

        $bindings = $organization->moduleEntitlements()->with('module')->get()
            ->filter(fn (OrganizationModule $binding) => $binding->module !== null)
            ->keyBy(fn (OrganizationModule $binding) => $binding->module->key);

        $sources = [];

        foreach (ModuleRegistry::all() as $module) {
            $key = $module['key'];
            $binding = $bindings->get($key);

            $sources[$key] = match (true) {
                $module['is_core'] => 'core',
                $binding !== null && $binding->inForce() => $binding->is_enabled ? 'manual_grant' : 'manual_revoke',
                in_array($key, $snapshot, true) => 'plan',
                in_array($key, $planned, true) => 'addon',
                default => 'off',
            };
        }

        return $sources;
    }

    private function facts(): SubscriptionFacts
    {
        return $this->subscriptions ?? app(SubscriptionFacts::class);
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
     * The catalogue with each row's standing for this organization, for the
     * binding screen.
     *
     * @return list<array<string, mixed>>
     */
    public function overview(Organization $organization): array
    {
        $bindings = $organization->moduleEntitlements()->get()->keyBy('module_id');
        $enabled = $this->enabled($organization);
        $sources = $this->sources($organization);

        return Module::available()
            ->orderBy('sort_order')
            ->get()
            ->map(function (Module $module) use ($bindings, $enabled, $sources) {
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

                    // What the organization actually gets, after the plan and
                    // any override — and where that answer came from.
                    'is_live' => in_array($module->key, $enabled, true),
                    'source' => $sources[$module->key] ?? 'off',
                    'reason' => $binding?->reason,

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
