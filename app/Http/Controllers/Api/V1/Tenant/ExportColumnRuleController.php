<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Platform\Organization;
use App\Models\Tenant\ExportColumnRule;
use App\Models\Tenant\Role;
use App\Services\Exports\Column;
use App\Services\Exports\Dataset;
use App\Services\Exports\ExportAccess;
use App\Services\Exports\ExportCatalog;
use App\Services\Tenancy\TenantConnectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Which columns each role may take out of the Export Center.
 *
 * Behind `exports.columns`, which is organization-scoped: the screen reaches
 * every role, a branch's own included. What a role may export AT ALL is still
 * the role screen's — a tab's capability — and this narrows it further, one
 * export at a time: the receptionist's invoices without the discount column,
 * the patient register without phone numbers.
 *
 * @group Tenant > Export center
 */
class ExportColumnRuleController extends BaseApiController
{
    public function __construct(
        private readonly ExportCatalog $catalog,
        private readonly ExportAccess $access,
    ) {}

    /** Every staff role, what each may export, and the columns each has hidden. */
    public function index(Request $request): JsonResponse
    {
        $organization = $request->attributes->get('tenant.organization');

        abort_unless($organization instanceof Organization, 403);

        $rules = ExportColumnRule::query()->get()->groupBy('role_id');

        $roles = Role::query()
            ->with(['capabilities', 'location:id,name'])
            ->orderBy('name')
            ->get()
            ->reject(fn (Role $role) => $role->isForPatients())
            ->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'icon' => $role->icon,
                'scope' => $role->scope,
                'branch' => $role->location?->name,
                'tabs' => collect(ExportCatalog::TABS)->mapWithKeys(fn (array $tab, string $key) => [$key => $role->grants($tab['capability'])]),
                'hidden' => (object) ($rules->get($role->id)?->pluck('hidden_columns', 'dataset')->all() ?? []),
            ])
            ->values();

        $tabs = collect(ExportCatalog::TABS)
            ->map(fn (array $tab, string $key) => [
                'key' => $key,
                'label' => $tab['label'],
                'icon' => $tab['icon'],
                'datasets' => collect($this->catalog->forTab($key))
                    ->filter(fn (Dataset $dataset) => $this->access->offers($organization, $dataset))
                    ->map(fn (Dataset $dataset) => [
                        'key' => $dataset->key(),
                        'label' => $dataset->label(),
                        'icon' => $dataset->icon(),
                        'columns' => array_map(fn (Column $column) => $column->toArray(), $dataset->columns()),
                    ])
                    ->values(),
            ])
            ->filter(fn (array $tab) => $tab['datasets']->isNotEmpty())
            ->values();

        return $this->ok(['tabs' => $tabs, 'roles' => $roles]);
    }

    /**
     * One role's rules, whole: every export not named, or named with nothing
     * hidden, goes back to every column.
     */
    public function update(Request $request, Role $role): JsonResponse
    {
        if ($role->isForPatients()) {
            throw ValidationException::withMessages(['role' => 'Patients do not export records.']);
        }

        $data = $request->validate([
            'hidden' => ['present', 'array'],
            'hidden.*' => ['array'],
            'hidden.*.*' => ['string', 'max:40'],
        ]);

        $wanted = [];

        foreach ($data['hidden'] as $key => $columns) {
            $dataset = $this->catalog->find((string) $key);

            if ($dataset === null) {
                throw ValidationException::withMessages(['hidden' => "There is no export called “{$key}”."]);
            }

            $unknown = array_diff($columns, array_keys($dataset->columnMap()));

            if ($unknown !== []) {
                throw ValidationException::withMessages(['hidden' => "{$dataset->label()} has no column “".reset($unknown).'”.']);
            }

            $columns = array_values(array_unique($columns));

            if ($columns !== []) {
                $wanted[$dataset->key()] = $columns;
            }
        }

        DB::connection(TenantConnectionService::CONNECTION)->transaction(function () use ($role, $wanted) {
            ExportColumnRule::query()
                ->where('role_id', $role->id)
                ->whereNotIn('dataset', array_keys($wanted))
                ->delete();

            foreach ($wanted as $dataset => $hidden) {
                ExportColumnRule::query()->updateOrCreate(
                    ['role_id' => $role->id, 'dataset' => $dataset],
                    ['hidden_columns' => $hidden],
                );
            }
        });

        return $this->ok(['hidden' => (object) $wanted], 'Column access saved');
    }
}
