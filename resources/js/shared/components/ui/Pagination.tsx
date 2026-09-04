import { cn } from '@/shared/utils/cn';

/** A compact page list: 1 … 4 5 6 … 12 */
function pageNumbers(current: number, total: number): (number | 'gap')[] {
    if (total <= 7) {
        return Array.from({ length: total }, (_, index) => index + 1);
    }

    const pages = new Set<number>([1, total, current]);

    for (const page of [current - 1, current + 1]) {
        if (page >= 1 && page <= total) {
            pages.add(page);
        }
    }

    const sorted = [...pages].sort((a, b) => a - b);
    const out: (number | 'gap')[] = [];

    sorted.forEach((page, index) => {
        if (index > 0 && page - sorted[index - 1] > 1) {
            out.push('gap');
        }

        out.push(page);
    });

    return out;
}

/**
 * Numbered paging, for lists that are not tables.
 *
 * DataTable has its own, wired to useServerTable. This is the same control
 * for everything else — the history timelines, which page but have no
 * columns — so the two look and behave alike instead of one screen getting
 * "Newer / Older" and another getting page numbers.
 */
export function Pagination({
    page,
    pageCount,
    total,
    perPage,
    onChange,
}: {
    /** 1-based, as the API counts them. */
    page: number;
    pageCount: number;
    total: number;
    perPage: number;
    onChange: (page: number) => void;
}) {
    if (total === 0) {
        return null;
    }

    const from = (page - 1) * perPage + 1;
    const to = Math.min(page * perPage, total);

    return (
        <div className="dt-foot">
            <span className="dt-info">
                Showing {from}–{to} of {total}
            </span>

            {pageCount > 1 && (
                <nav className="dt-pages" aria-label="Pagination">
                    <button
                        type="button"
                        className="dt-page"
                        aria-label="Previous page"
                        disabled={page <= 1}
                        onClick={() => onChange(page - 1)}
                    >
                        <i className="ti ti-chevron-left" />
                    </button>

                    {pageNumbers(page, pageCount).map((entry, index) =>
                        entry === 'gap' ? (
                            <span key={`gap-${index}`} className="dt-info px-1">
                                …
                            </span>
                        ) : (
                            <button
                                type="button"
                                key={entry}
                                className={cn('dt-page', entry === page && 'is-active')}
                                aria-current={entry === page ? 'page' : undefined}
                                onClick={() => onChange(entry)}
                            >
                                {entry}
                            </button>
                        ),
                    )}

                    <button
                        type="button"
                        className="dt-page"
                        aria-label="Next page"
                        disabled={page >= pageCount}
                        onClick={() => onChange(page + 1)}
                    >
                        <i className="ti ti-chevron-right" />
                    </button>
                </nav>
            )}
        </div>
    );
}
