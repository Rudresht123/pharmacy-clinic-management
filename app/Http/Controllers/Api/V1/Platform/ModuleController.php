<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Concerns\HandlesTableQueries;
use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Platform\Module;
use App\Models\Platform\Organization;
use App\Models\Platform\OrganizationModule;
use App\Services\Modules\ModuleAccess;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The module catalogue, platform-wide.
 *
 * Read-only on purpose. The catalogue is a mirror of
 * App\Support\Modules\ModuleRegistry and is reconciled by `modules:sync` on
 * every deploy, so anything edited here would be silently reverted on the
 * next release — a screen that quietly undoes your work is worse than one
 * that does not offer it. What this answers instead is the question a
 * platform administrator actually has: who is on each module, and whose
 * subscription is about to lapse.
 */
class ModuleController extends BaseApiController
{
    use HandlesTableQueries;

    /** Anything ending within this many days is worth chasing now. */
    private const EXPIRING_SOON_DAYS = 30;

    public function __construct(
        private readonly ModuleAccess $access,
    ) {}

    /**
     * Who has what — the list an administrator assigning a module starts from.
     *
     * Counts are computed in PHP over the page rather than in SQL, because
     * "has this module" is three conditions (enabled, started, unexpired) and
     * ModuleAccess is the one place allowed to decide them. A COUNT(*) here
     * would be a fourth opinion, and the first one to drift.
     */
    public function organizations(Request $request): JsonResponse
    {
        $query = Organization::query()->with('organizationType');

        $page = $this->tableQuery(
            $query,
            $request,
            searchable: ['organization_name', 'organization_code', 'subdomain', 'email'],
            sortable: ['organization_name', 'organization_code', 'status', 'created_at'],
            defaultSort: 'organization_name',
            defaultDirection: 'asc',
        );

        $coreCount = count(ModuleRegistry::coreKeys());

        $page->getCollection()->transform(function (Organization $organization) use ($coreCount) {
            $enabled = $this->access->enabled($organization);

            $expiringSoon = $organization->moduleEntitlements()
                ->get()
                ->filter(fn (OrganizationModule $row) => $row->isLive()
                    && $row->expires_at !== null
                    && $row->expires_at->isBefore(now()->addDays(self::EXPIRING_SOON_DAYS)))
                ->count();

            return [
                'uuid' => $organization->uuid,
                'name' => $organization->organization_name,
                'code' => $organization->organization_code,
                'subdomain' => $organization->subdomain,
                'status' => $organization->status,
                'type' => $organization->organizationType?->name,

                // Core modules are excluded: every organization has them, so
                // including them would make every row read the same.
                'modules' => count($enabled) - $coreCount,
                'expiring_soon' => $expiringSoon,
                'capabilities' => count($this->access->capabilities($organization)),
            ];
        });

        /*
         * Built by hand rather than through a Resource: these rows are a
         * summary assembled here, not a model with a canonical shape, and a
         * Resource for one screen would be a class that only ever means one
         * thing.
         */
        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
            ],
        ]);
    }

    public function index(): JsonResponse
    {
        $entitlements = OrganizationModule::with('module')->get();

        $modules = Module::available()
            ->orderBy('sort_order')
            ->get()
            ->map(function (Module $module) use ($entitlements) {
                $mine = $entitlements->where('module_id', $module->id);

                $live = $mine->filter(fn (OrganizationModule $row) => $row->isLive());

                return [
                    'key' => $module->key,
                    'name' => $module->name,
                    'description' => $module->description,
                    'group' => $module->group,
                    'icon' => $module->icon,
                    'is_core' => $module->is_core,

                    /*
                     * Core modules are held by every organization by virtue
                     * of being one, so counting rows would report zero. Null
                     * says "not a number that means anything here" rather
                     * than a wrong one.
                     */
                    'organizations' => $module->is_core ? null : $live->count(),
                    'assigned' => $module->is_core ? null : $mine->count(),

                    'expiring_soon' => $module->is_core ? null : $live->filter(
                        fn (OrganizationModule $row) => $row->expires_at !== null
                            && $row->expires_at->isBefore(now()->addDays(self::EXPIRING_SOON_DAYS))
                    )->count(),

                    'capabilities' => $module->capabilities(),
                ];
            })
            ->values()
            ->all();

        return $this->ok([
            'modules' => $modules,
            'groups' => array_values(array_unique(array_column($modules, 'group'))),

            // Every permission the software knows about, whoever has it.
            'capability_count' => count(ModuleRegistry::capabilitiesFor(ModuleRegistry::keys())),
        ]);
    }
}
