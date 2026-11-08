<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Platform\Organization;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Location;
use App\Models\Tenant\User;
use App\Services\Exports\Column;
use App\Services\Exports\Dataset;
use App\Services\Exports\ExportAccess;
use App\Services\Exports\ExportCatalog;
use App\Services\Exports\ExportFilters;
use App\Services\Exports\ExportPlanner;
use App\Services\Exports\ExportWriter;
use App\Services\Permissions\Permission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Export Center — patient, pharmacy, billing and communication records,
 * as Excel, CSV or PDF, in one place.
 *
 * Every route here is open to anybody signed in, because no single
 * capability guards them: each export belongs to a tab, and the tab's
 * capability — held at which branches — is ExportAccess's answer, asked on
 * every request. Somebody who may export nothing is shown no tabs, and asking
 * for an export by its name anyway is refused.
 *
 * A whole export is made on the queue (ExportRunController); the direct
 * download here serves the rows ticked on screen — a hundred at most.
 *
 * @group Tenant > Export center
 */
class ExportCenterController extends BaseApiController
{
    public function __construct(
        private readonly ExportCatalog $catalog,
        private readonly ExportAccess $access,
        private readonly ExportWriter $writer,
        private readonly ExportPlanner $planner,
        private readonly Permission $permission,
    ) {}

    /**
     * The tabs this person may export, each with its branches and its
     * exports — every export already narrowed to the columns their roles let
     * them take.
     */
    public function index(Request $request): JsonResponse
    {
        [$organization, $user] = $this->caller($request);

        $locations = Location::query()->orderBy('name')->get(['id', 'name']);
        $tabs = [];

        foreach (ExportCatalog::TABS as $key => $tab) {
            $branches = $this->access->branches($organization, $user, $key);

            if ($branches === []) {
                continue;
            }

            $datasets = [];

            foreach ($this->catalog->forTab($key) as $dataset) {
                if (! $this->access->offers($organization, $dataset)) {
                    continue;
                }

                $columns = $this->access->columns($user, $dataset, $branches);

                // Every column hidden from them is this export taken away.
                if ($columns !== []) {
                    $datasets[] = $this->describe($dataset, $columns);
                }
            }

            if ($datasets === []) {
                continue;
            }

            $tabs[] = [
                'key' => $key,
                'label' => $tab['label'],
                'icon' => $tab['icon'],
                'all_branches' => $branches === null,
                'branches' => $locations
                    ->when($branches !== null, fn ($rows) => $rows->whereIn('id', $branches))
                    ->map(fn (Location $location) => ['id' => $location->id, 'name' => $location->name])
                    ->values(),
                'datasets' => $datasets,
            ];
        }

        // The doctor filter's choices, once, for whichever exports offer it.
        $doctorFilter = collect($tabs)->pluck('datasets')->flatten(1)->contains('doctor_filter', true);

        return $this->ok([
            'tabs' => $tabs,
            'doctors' => $doctorFilter
                ? Doctor::query()->orderBy('name')->get(['id', 'name'])->map(fn (Doctor $doctor) => ['id' => $doctor->id, 'name' => $doctor->name])
                : [],
            'can_manage_columns' => $this->permission->allows($organization, $user, 'exports.columns'),
            'pdf_limit' => ExportWriter::PDF_LIMIT,
        ]);
    }

    /**
     * The rows the filters match, a page at a time, as the file will have
     * them — with what only the screen shows beside: a second line under
     * some cells, and where each record opens.
     */
    public function preview(Request $request, string $dataset): JsonResponse
    {
        $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', Rule::in([10, 25, 50, 100])],
        ]);

        [$found, $filters, $columns] = $this->prepare($request, $dataset);

        $query = $found->rows($filters);

        // Counted before ordering, which can name a column a count does not select.
        $total = (clone $query)->count();

        $perPage = $request->integer('per_page', 10) ?: 10;
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($request->integer('page', 1) ?: 1, $lastPage);

        $rows = $this->planner->ordered($found, $query, $request->all())
            // The patients' photographs, for the avatars — the table's, never a file's.
            ->when($found->photoPath(), fn (Builder $q, string $path) => $q->with($path))
            ->forPage($page, $perPage)
            ->get()
            ->map(fn (Model $record) => [
                'id' => $record->getKey(),
                'cells' => $this->writer->displayRow($columns, $found->row($record)),
                'subs' => (object) $found->subtitles($record),
                'link' => $found->link($record),
                'photo' => $found->photo($record),
                'muted' => $found->muted($record),
            ]);

        return $this->ok([
            'total' => $total,
            'columns' => array_map(fn (Column $column) => $column->toArray(), $columns),
            'rows' => $rows,
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'from' => $total === 0 ? 0 : ($page - 1) * $perPage + 1,
                'to' => min($page * $perPage, $total),
            ],
        ]);
    }

    /** The file at once — every row the filters match (or only the ones ticked), in the columns chosen. */
    public function download(Request $request, string $dataset): Response|StreamedResponse|BinaryFileResponse
    {
        $request->validate(['format' => ['required', Rule::in(ExportWriter::FORMATS)]]);

        [$found, $filters, $columns] = $this->prepare($request, $dataset);

        $query = $found->rows($filters);
        $total = (clone $query)->count();

        return $this->writer->download(
            (string) $request->input('format'),
            str_replace('.', '-', $found->key()),
            $columns,
            $this->planner->ordered($found, $query, $request->all()),
            fn (Model $record) => $found->row($record),
            $total,
            $this->planner->heading($found, $filters),
            muted: fn (Model $record) => $found->muted($record),
        );
    }

    /* ------------------------------------------------------------------ */

    /**
     * The export asked for, narrowed — see ExportPlanner.
     *
     * @return array{0: Dataset, 1: ExportFilters, 2: list<Column>}
     */
    private function prepare(Request $request, string $key): array
    {
        $input = $request->validate(ExportPlanner::rules());

        [$organization, $user] = $this->caller($request);

        return $this->planner->plan($organization, $user, $key, $input);
    }

    /**
     * @param  list<Column>  $columns
     * @return array<string, mixed>
     */
    private function describe(Dataset $dataset, array $columns): array
    {
        return [
            'key' => $dataset->key(),
            'label' => $dataset->label(),
            'description' => $dataset->description(),
            'icon' => $dataset->icon(),
            'noun' => $dataset->noun(),
            'columns' => array_map(fn (Column $column) => $column->toArray(), $columns),
            'date_label' => $dataset->dateLabel(),
            'default_period' => $dataset->defaultPeriod(),
            'statuses' => collect($dataset->statuses())
                ->map(fn (string $label, string|int $value) => ['value' => (string) $value, 'label' => $label])
                ->values(),
            'status_label' => $dataset->statusLabel(),
            'search_hint' => $dataset->searchHint(),
            'sortable' => array_keys($dataset->sortable()),
            'doctor_filter' => $dataset->doctorColumn() !== null,
        ];
    }

    /** @return array{0: Organization, 1: User} */
    private function caller(Request $request): array
    {
        $organization = $request->attributes->get('tenant.organization');
        $user = $request->user();

        abort_unless($organization instanceof Organization && $user instanceof User, 403);

        return [$organization, $user];
    }
}
