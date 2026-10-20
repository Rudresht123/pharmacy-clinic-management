import { useState } from 'react';
import { cn } from '@/shared/utils/cn';

const PER_PAGE = [10, 25, 50, 100];

/** 1 2 3 4 5 … 264 near the start, 1 … 6 7 8 … 264 in the middle, 1 … 260–264 at the end. */
function pages(current: number, last: number): (number | 'gap')[] {
    if (last <= 7) {
        return Array.from({ length: last }, (_, index) => index + 1);
    }

    if (current <= 4) {
        return [1, 2, 3, 4, 5, 'gap', last];
    }

    if (current >= last - 3) {
        return [1, 'gap', last - 4, last - 3, last - 2, last - 1, last];
    }

    return [1, 'gap', current - 1, current, current + 1, 'gap', last];
}

/**
 * The foot of the records table: where in the list this is, how many to a
 * page, the page buttons — first, previous, numbers, next, last — and a
 * "go to" box for a list of hundreds of pages.
 */
export function Pager({
    page,
    lastPage,
    perPage,
    total,
    from,
    to,
    onPage,
    onPerPage,
}: {
    page: number;
    lastPage: number;
    perPage: number;
    total: number;
    from: number;
    to: number;
    onPage: (page: number) => void;
    onPerPage: (perPage: number) => void;
}) {
    const [goTo, setGoTo] = useState('');

    const jump = () => {
        const target = Number(goTo);

        if (Number.isInteger(target) && target >= 1 && target <= lastPage) {
            onPage(target);
        }

        setGoTo('');
    };

    return (
        <footer className="exc-pager">
            <span className="exc-pager-info">
                Showing <b>{from.toLocaleString('en-IN')}</b> – <b>{to.toLocaleString('en-IN')}</b> of{' '}
                <b>{total.toLocaleString('en-IN')}</b> records
            </span>

            <div className="exc-pager-controls">
                <span className="exc-select">
                    <select value={perPage} onChange={(event) => onPerPage(Number(event.target.value))} aria-label="Rows per page">
                        {PER_PAGE.map((size) => (
                            <option key={size} value={size}>
                                {size} per page
                            </option>
                        ))}
                    </select>
                    <i className="ti ti-chevron-down" aria-hidden="true" />
                </span>

                <nav className="exc-pages" aria-label="Pages">
                    <button type="button" onClick={() => onPage(1)} disabled={page <= 1} aria-label="First page">
                        <i className="ti ti-chevrons-left" />
                    </button>
                    <button type="button" onClick={() => onPage(page - 1)} disabled={page <= 1} aria-label="Previous page">
                        <i className="ti ti-chevron-left" />
                    </button>

                    {pages(page, lastPage).map((entry, index) =>
                        entry === 'gap' ? (
                            <span key={`gap-${index}`} className="exc-pages-gap">
                                …
                            </span>
                        ) : (
                            <button
                                key={entry}
                                type="button"
                                className={cn(entry === page && 'is-on')}
                                aria-current={entry === page ? 'page' : undefined}
                                onClick={() => onPage(entry)}
                            >
                                {entry.toLocaleString('en-IN')}
                            </button>
                        ),
                    )}

                    <button type="button" onClick={() => onPage(page + 1)} disabled={page >= lastPage} aria-label="Next page">
                        <i className="ti ti-chevron-right" />
                    </button>
                    <button type="button" onClick={() => onPage(lastPage)} disabled={page >= lastPage} aria-label="Last page">
                        <i className="ti ti-chevrons-right" />
                    </button>
                </nav>

                <span className="exc-goto">
                    <label htmlFor="exc-goto">Go to</label>
                    <input
                        id="exc-goto"
                        type="number"
                        min={1}
                        max={lastPage}
                        value={goTo}
                        placeholder={String(page)}
                        onChange={(event) => setGoTo(event.target.value)}
                        onKeyDown={(event) => event.key === 'Enter' && jump()}
                    />
                    <button type="button" onClick={jump} disabled={goTo === ''}>
                        Go
                    </button>
                </span>
            </div>
        </footer>
    );
}
