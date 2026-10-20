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
use App\Services\Exports\ExportWriter;
use App\Services\Permissions\Permission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
 * @group Tenant > Export center
 */
class ExportCenterController extends BaseApiController
{
    public function __construct(
        private readonly ExportCatalog $catalog,
        private readonly ExportAccess $access,
        private readonly ExportWriter $writer,
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

        $rows = $this->ordered($found, $query, $request)
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

    /** The file — every row the filters match (or only the ones ticked), in the columns chosen. */
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
            $this->ordered($found, $query, $request),
            fn (Model $record) => $found->row($record),
            $total,
            [
                'organization' => (string) $filters->organization->organization_name,
                'title' => ExportCatalog::TABS[$found->tab()]['label'].' — '.$found->label(),
                'noun' => $found->noun(),
                'by' => (string) $filters->user->name,
                'filters' => $this->summary($found, $filters),
            ],
            muted: fn (Model $record) => $found->muted($record),
        );
    }

    /* ------------------------------------------------------------------ */

    /** The screen's sort, when it named a sortable column; the export's own order otherwise. */
    private function ordered(Dataset $dataset, Builder $query, Request $request): Builder
    {
        return $dataset->sorted(
            $query,
            $request->filled('sort') ? (string) $request->input('sort') : null,
            (string) $request->input('direction', 'desc'),
        );
    }

    /**
     * The export asked for, narrowed: whether this person may have it at all,
     * at which branches, over which dates, in which columns.
     *
     * @return array{0: Dataset, 1: ExportFilters, 2: list<Column>}
     */
    private function prepare(Request $request, string $key): array
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'status' => ['nullable', 'string', 'max:40'],
            'location_id' => ['nullable', 'integer'],
            'doctor_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'columns' => ['nullable', 'string', 'max:2000'],
            'sort' => ['nullable', 'string', 'max:40'],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            // The rows ticked on screen — as many as one page can show.
            'ids' => ['nullable', 'string', 'regex:/^\d+(,\d+){0,99}$/'],
        ]);

        [$organization, $user] = $this->caller($request);

        $dataset = $this->catalog->find($key);

        abort_if($dataset === null || ! $this->access->offers($organization, $dataset), 404, 'There is no such export.');

        $allowed = $this->access->branches($organization, $user, $dataset->tab());

        abort_if($allowed === [], 403, 'You may not export these records.');

        $branches = $allowed;

        if ($request->filled('location_id')) {
            $asked = $request->integer('location_id');

            abort_if($allowed !== null && ! in_array($asked, $allowed, true), 403, 'You may not export that branch’s records.');

            $branches = [$asked];
        }

        $visible = $this->access->columns($user, $dataset, $branches);

        abort_if($visible === [], 403, 'You may not export these records.');

        // The columns ticked on screen, kept to the ones this person may have.
        $asked = array_filter(explode(',', (string) $request->input('columns', '')));
        $columns = $asked === []
            ? $visible
            : array_values(array_filter($visible, fn (Column $column) => in_array($column->key, $asked, true)));

        if ($columns === []) {
            throw ValidationException::withMessages(['columns' => 'Choose at least one column to export.']);
        }

        $status = $request->filled('status') ? (string) $request->input('status') : null;

        if ($status !== null && ! array_key_exists($status, $dataset->statuses())) {
            throw ValidationException::withMessages(['status' => 'That is not one of this export’s statuses.']);
        }

        return [
            $dataset,
            new ExportFilters(
                organization: $organization,
                user: $user,
                branches: $branches,
                from: $request->filled('from') ? Carbon::parse((string) $request->input('from')) : null,
                to: $request->filled('to') ? Carbon::parse((string) $request->input('to')) : null,
                status: $status,
                search: $request->filled('search') ? (string) $request->input('search') : null,
                doctor: $request->filled('doctor_id') ? $request->integer('doctor_id') : null,
                ids: $request->filled('ids') ? array_map('intval', explode(',', (string) $request->input('ids'))) : null,
            ),
            $columns,
        ];
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

    /**
     * What narrowed the file, in words, for the top of its first PDF page.
     *
     * @return list<string>
     */
    private function summary(Dataset $dataset, ExportFilters $filters): array
    {
        $period = match (true) {
            $filters->from !== null && $filters->to !== null => $filters->from->format('d M Y').' – '.$filters->to->format('d M Y'),
            $filters->from !== null => 'from '.$filters->from->format('d M Y'),
            $filters->to !== null => 'up to '.$filters->to->format('d M Y'),
            default => null,
        };

        $branches = $filters->branches === null
            ? null
            : Location::query()->whereIn('id', $filters->branches)->orderBy('name')->pluck('name')->implode(', ');

        return array_values(array_filter([
            $period !== null ? ($dataset->dateLabel() ?? 'Dates').' '.$period : null,
            $branches ? 'At '.$branches : null,
            $filters->status !== null ? ($dataset->statuses()[$filters->status] ?? $filters->status) : null,
            $filters->search !== null ? 'Search “'.trim($filters->search).'”' : null,
        ]));
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
