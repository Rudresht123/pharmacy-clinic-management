<?php

namespace App\Services\Exports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One kind of record the Export Center hands out — invoices, stock, the
 * message log — as a query and a way to read each row.
 *
 * Each export says three things and nothing about files: which rows (query,
 * already narrowed to the caller's branches and filters), in what order, and
 * what each row reads as, column by column. Excel, CSV and PDF are
 * ExportWriter's business, so a new export is one class here and it arrives
 * in all three formats at once.
 */
abstract class Dataset
{
    /** Unique across the catalogue, "tab.name" — e.g. "billing.invoices". */
    abstract public function key(): string;

    /** Which Export Center tab it sits under; see ExportCatalog::TABS. */
    abstract public function tab(): string;

    abstract public function label(): string;

    /** One line under the title, saying what is in it. */
    abstract public function description(): string;

    /** What one row is, plural — "invoices" — for "1,204 invoices". */
    abstract public function noun(): string;

    /** @return list<Column> */
    abstract public function columns(): array;

    /** The rows, narrowed to the branches and filters — unordered, so they can be counted. */
    abstract public function query(ExportFilters $filters): Builder;

    /** The rows' order, applied after counting. */
    abstract public function order(Builder $query): Builder;

    /**
     * One record as the export reads it, keyed by column key. Money and
     * counts as numbers; dates already as they print.
     *
     * @return array<string, string|int|float|null>
     */
    abstract public function row(Model $record): array;

    public function icon(): string
    {
        return 'ti ti-table';
    }

    /**
     * A module this export needs on top of its tab's — the lab's orders sit
     * under Patients but need the laboratory to have been sold.
     */
    public function module(): ?string
    {
        return null;
    }

    /** What the date range narrows ("Invoice date"), or null for an export with none. */
    public function dateLabel(): ?string
    {
        return null;
    }

    /** The range the screen opens on: "month" for the day-to-day, "all" for a register. */
    public function defaultPeriod(): string
    {
        return 'month';
    }

    /** @return array<string, string> the status filter's values and their labels */
    public function statuses(): array
    {
        return [];
    }

    /** What that filter is called — a payment's "status" is how it was paid. */
    public function statusLabel(): string
    {
        return 'Status';
    }

    /** What the search box reaches, as its placeholder — null for no search. */
    public function searchHint(): ?string
    {
        return null;
    }

    /** Whether a PDF prints this row in grey — an inactive patient, say. */
    public function muted(Model $record): bool
    {
        return false;
    }

    /**
     * The columns a person may sort by, and what each sorts on.
     *
     * @return array<string, string> column key => database column
     */
    public function sortable(): array
    {
        return [];
    }

    /** The column holding the doctor, for an export the doctor filter applies to. */
    public function doctorColumn(): ?string
    {
        return null;
    }

    /**
     * A second, smaller line under some cells — on screen only, never in a
     * file: "Male, 32 yrs" under a patient's name.
     *
     * @return array<string, string> column key => the line
     */
    public function subtitles(Model $record): array
    {
        return [];
    }

    /** Where this record opens in the software, when it has a screen of its own. */
    public function link(Model $record): ?string
    {
        return null;
    }

    /**
     * The relation path to the photograph shown beside the patient on
     * screen — "customer.photograph" — or null for an export with no person
     * in it. Eager-loaded for the table only; a file carries no pictures.
     */
    public function photoPath(): ?string
    {
        return null;
    }

    /** That photograph's URL, for one record. */
    final public function photo(Model $record): ?string
    {
        $path = $this->photoPath();

        return $path !== null ? data_get($record, $path.'.url') : null;
    }

    /**
     * The rows, narrowed by everything the Export Center filters on: the
     * export's own filters, the doctor, and the rows ticked on screen.
     */
    final public function rows(ExportFilters $filters): Builder
    {
        $query = $this->query($filters);

        if ($filters->doctor !== null && ($column = $this->doctorColumn()) !== null) {
            $query->where($column, $filters->doctor);
        }

        if ($filters->ids !== null) {
            $query->whereKey($filters->ids);
        }

        return $query;
    }

    /** In the order asked for, when it is a sortable column; the export's own otherwise. */
    final public function sorted(Builder $query, ?string $sort, string $direction): Builder
    {
        $column = $sort !== null ? ($this->sortable()[$sort] ?? null) : null;

        if ($column === null) {
            return $this->order($query);
        }

        $direction = $direction === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($column, $direction)->orderBy($query->getModel()->getQualifiedKeyName(), $direction);
    }

    /** @return array<string, Column> */
    public function columnMap(): array
    {
        $map = [];

        foreach ($this->columns() as $column) {
            $map[$column->key] = $column;
        }

        return $map;
    }

    /*
    |--------------------------------------------------------------------------
    | For the exports themselves
    |--------------------------------------------------------------------------
    */

    /** Only the caller's branches, through whichever column holds the branch. */
    protected function atBranches(Builder $query, string $column, ExportFilters $filters): Builder
    {
        return $filters->branches === null ? $query : $query->whereIn($column, $filters->branches);
    }

    /** The date range over one column — whole days, both ends included. */
    protected function between(Builder $query, string $column, ExportFilters $filters): Builder
    {
        return $query
            ->when($filters->from, fn (Builder $q, Carbon $from) => $q->where($column, '>=', $from->copy()->startOfDay()))
            ->when($filters->to, fn (Builder $q, Carbon $to) => $q->where($column, '<=', $to->copy()->endOfDay()));
    }

    protected function date(mixed $value): ?string
    {
        return $value ? Carbon::parse($value)->format('d M Y') : null;
    }

    protected function dateTime(mixed $value): ?string
    {
        return $value ? Carbon::parse($value)->format('d M Y, h:i A') : null;
    }

    /** "partially_paid" → "Partially paid". */
    protected function words(?string $value): ?string
    {
        return $value === null || $value === '' ? null : ucfirst(str_replace('_', ' ', $value));
    }

    protected function money(mixed $value): float
    {
        return round((float) $value, 2);
    }
}
