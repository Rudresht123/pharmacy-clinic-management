<?php

namespace App\Http\Concerns;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Search, sort and paginate a listing from query-string parameters.
 *
 * Every table endpoint speaks the same dialect:
 *
 *   ?page=2&per_page=25&search=clinic&sort=name&direction=asc
 *
 * so the React DataTable can drive any resource without special cases.
 */
trait HandlesTableQueries
{
    /** Hard ceiling so a client cannot ask for the whole table at once. */
    protected int $maxPerPage = 100;

    /**
     * @param  array<int, string>  $searchable  Columns matched against ?search
     * @param  array<int, string>  $sortable    Columns allowed in ?sort
     */
    protected function tableQuery(
        Builder $query,
        Request $request,
        array $searchable = [],
        array $sortable = [],
        string $defaultSort = 'id',
        string $defaultDirection = 'desc',
    ): LengthAwarePaginator {
        $this->applySearch($query, $request, $searchable);
        $this->applySort($query, $request, $sortable, $defaultSort, $defaultDirection);

        return $query->paginate($this->resolvePerPage($request))->withQueryString();
    }

    /**
     * @param  array<int, string>  $searchable
     */
    protected function applySearch(Builder $query, Request $request, array $searchable): void
    {
        $term = trim((string) $request->query('search', ''));

        if ($term === '' || $searchable === []) {
            return;
        }

        $pattern = '%' . $term . '%';

        // Grouped so the OR set cannot leak past other filters.
        $query->where(function (Builder $builder) use ($searchable, $pattern) {
            foreach ($searchable as $column) {
                $builder->orWhere($column, 'ILIKE', $pattern);
            }
        });
    }

    /**
     * Only whitelisted columns are accepted — the sort key reaches SQL as an
     * identifier, so it can never come straight from the request.
     *
     * @param  array<int, string>  $sortable
     */
    protected function applySort(
        Builder $query,
        Request $request,
        array $sortable,
        string $defaultSort,
        string $defaultDirection,
    ): void {
        $requested = (string) $request->query('sort', '');
        $column = in_array($requested, $sortable, true) ? $requested : $defaultSort;

        $direction = strtolower((string) $request->query('direction', ''));

        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = $column === $defaultSort ? $defaultDirection : 'asc';
        }

        $query->orderBy($column, $direction);

        // Keep paging stable when the sort column has duplicate values.
        if ($column !== 'id') {
            $query->orderBy('id', 'desc');
        }
    }

    protected function resolvePerPage(Request $request): int
    {
        $perPage = (int) $request->query('per_page', 25);

        return max(1, min($perPage, $this->maxPerPage));
    }
}
