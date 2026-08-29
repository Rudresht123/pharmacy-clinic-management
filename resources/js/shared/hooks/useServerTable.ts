import { useEffect, useMemo, useState } from 'react';
import type { SortingState } from '@tanstack/react-table';
import { useDebounce } from './useDebounce';

export interface TableQueryParams {
    page: number;
    per_page: number;
    search?: string;
    sort?: string;
    direction?: 'asc' | 'desc';
}

export interface ServerTable {
    pageIndex: number;
    pageSize: number;
    sorting: SortingState;
    search: string;
    setPageIndex(index: number): void;
    setPageSize(size: number): void;
    setSorting(sorting: SortingState): void;
    setSearch(value: string): void;
    /** Query string the API expects. */
    params: TableQueryParams;
}

interface Options {
    pageSize?: number;
    /** Column id to sort by initially. */
    sort?: string;
    direction?: 'asc' | 'desc';
}

/**
 * Table state that lives on the server: page, size, sort and search.
 *
 * Pair with `<DataTable server={…} />` and a paginated endpoint — the
 * browser then only ever holds one page of rows, which is what makes this
 * workable once a table has thousands of records.
 */
export function useServerTable(options: Options = {}): ServerTable {
    const [pageIndex, setPageIndex] = useState(0);
    const [pageSize, setPageSize] = useState(options.pageSize ?? 25);
    const [sorting, setSorting] = useState<SortingState>(
        options.sort ? [{ id: options.sort, desc: options.direction !== 'asc' }] : [],
    );
    const [search, setSearch] = useState('');

    const debouncedSearch = useDebounce(search);

    // Changing what is being looked at invalidates the current page number:
    // page 4 of an unfiltered list is rarely page 4 of a filtered one.
    useEffect(() => {
        setPageIndex(0);
    }, [debouncedSearch, pageSize, sorting]);

    const params = useMemo<TableQueryParams>(() => {
        const active = sorting[0];

        return {
            page: pageIndex + 1,
            per_page: pageSize,
            search: debouncedSearch.trim() || undefined,
            sort: active?.id,
            direction: active ? (active.desc ? 'desc' : 'asc') : undefined,
        };
    }, [pageIndex, pageSize, debouncedSearch, sorting]);

    return {
        pageIndex,
        pageSize,
        sorting,
        search,
        setPageIndex,
        setPageSize,
        setSorting,
        setSearch,
        params,
    };
}
