<?php

namespace App\Services\Exports;

use App\Models\Platform\Organization;
use App\Models\Tenant\Location;
use App\Models\Tenant\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * One export as asked for, narrowed: whether this person may have it at all,
 * at which branches, over which dates, in which columns.
 *
 * Asked twice for a file made on the queue — when it is asked for, so a
 * refusal is immediate, and again when the job runs, because a role can
 * change in between and the file must obey the role as it is when written.
 * The screen's preview and the direct download ask it once.
 */
final class ExportPlanner
{
    /** What may narrow an export, as the screen sends it. */
    public const FILTERS = ['from', 'to', 'status', 'location_id', 'doctor_id', 'search', 'columns', 'sort', 'direction', 'ids'];

    public function __construct(
        private readonly ExportCatalog $catalog,
        private readonly ExportAccess $access,
    ) {}

    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
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
        ];
    }

    /**
     * Only what narrows, as kept on a queued run: empty filters dropped.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public static function keep(array $input): array
    {
        $kept = [];

        foreach (self::FILTERS as $key) {
            $value = $input[$key] ?? null;

            if ($value !== null && $value !== '') {
                $kept[$key] = (string) $value;
            }
        }

        return $kept;
    }

    /**
     * @param  array<string, mixed>  $input  already validated against rules()
     * @return array{0: Dataset, 1: ExportFilters, 2: list<Column>}
     */
    public function plan(Organization $organization, User $user, string $key, array $input): array
    {
        $filled = fn (string $name) => isset($input[$name]) && $input[$name] !== '';

        $dataset = $this->catalog->find($key);

        abort_if($dataset === null || ! $this->access->offers($organization, $dataset), 404, 'There is no such export.');

        $allowed = $this->access->branches($organization, $user, $dataset->tab());

        abort_if($allowed === [], 403, 'You may not export these records.');

        $branches = $allowed;

        if ($filled('location_id')) {
            $asked = (int) $input['location_id'];

            abort_if($allowed !== null && ! in_array($asked, $allowed, true), 403, 'You may not export that branch’s records.');

            $branches = [$asked];
        }

        $visible = $this->access->columns($user, $dataset, $branches);

        abort_if($visible === [], 403, 'You may not export these records.');

        // The columns ticked on screen, kept to the ones this person may have.
        $asked = array_filter(explode(',', (string) ($input['columns'] ?? '')));
        $columns = $asked === []
            ? $visible
            : array_values(array_filter($visible, fn (Column $column) => in_array($column->key, $asked, true)));

        if ($columns === []) {
            throw ValidationException::withMessages(['columns' => 'Choose at least one column to export.']);
        }

        $status = $filled('status') ? (string) $input['status'] : null;

        if ($status !== null && ! array_key_exists($status, $dataset->statuses())) {
            throw ValidationException::withMessages(['status' => 'That is not one of this export’s statuses.']);
        }

        return [
            $dataset,
            new ExportFilters(
                organization: $organization,
                user: $user,
                branches: $branches,
                from: $filled('from') ? Carbon::parse((string) $input['from']) : null,
                to: $filled('to') ? Carbon::parse((string) $input['to']) : null,
                status: $status,
                search: $filled('search') ? (string) $input['search'] : null,
                doctor: $filled('doctor_id') ? (int) $input['doctor_id'] : null,
                ids: $filled('ids') ? array_map('intval', explode(',', (string) $input['ids'])) : null,
            ),
            $columns,
        ];
    }

    /**
     * The screen's sort, when it named a sortable column; the export's own order otherwise.
     *
     * @param  array<string, mixed>  $input
     */
    public function ordered(Dataset $dataset, Builder $query, array $input): Builder
    {
        $sort = isset($input['sort']) && $input['sort'] !== '' ? (string) $input['sort'] : null;

        return $dataset->sorted($query, $sort, (string) ($input['direction'] ?? 'desc'));
    }

    /**
     * What the top of the file says: whose records, which export, who asked,
     * and what narrowed it.
     *
     * @return array{organization: string, title: string, tab: string, export: string, noun: string, by: string, filters: list<string>}
     */
    public function heading(Dataset $dataset, ExportFilters $filters): array
    {
        $tab = ExportCatalog::TABS[$dataset->tab()]['label'];

        return [
            'organization' => (string) $filters->organization->organization_name,
            'title' => $tab.' — '.$dataset->label(),
            'tab' => $tab,
            'export' => $dataset->label(),
            'noun' => $dataset->noun(),
            'by' => (string) $filters->user->name,
            'filters' => $this->summary($dataset, $filters),
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
            $filters->ids !== null ? count($filters->ids).' selected' : null,
        ]));
    }
}
