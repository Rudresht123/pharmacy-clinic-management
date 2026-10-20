import type { ReactNode } from 'react';
import { cn } from '@/shared/utils/cn';
import type { StatusPill } from '../labels';

/*
 * The small pieces every section of the record is built from, so the eight
 * tabs read as one screen rather than eight.
 */

/** A white card with a title row — the project's card style, see patient-records.css. */
export function RecordCard({
    title,
    icon,
    action,
    className,
    children,
}: {
    title?: ReactNode;
    icon?: string;
    action?: ReactNode;
    className?: string;
    children: ReactNode;
}) {
    return (
        <section className={cn('ptr-card', className)}>
            {(title || action) && (
                <header className="ptr-card-head">
                    {title && (
                        <h3>
                            {icon && <i className={icon} aria-hidden="true" />}
                            {title}
                        </h3>
                    )}
                    {action && <div className="ptr-card-action">{action}</div>}
                </header>
            )}
            {children}
        </section>
    );
}

export function Pill({ pill }: { pill: StatusPill | null }) {
    if (!pill) return null;

    return <span className={`ptr-pill is-${pill.tone}`}>{pill.label}</span>;
}

/** What a section says when there is nothing in it — never a row of zeroes. */
export function Empty({ icon, title, line }: { icon: string; title: string; line?: string }) {
    return (
        <div className="ptr-empty">
            <span aria-hidden="true">
                <i className={icon} />
            </span>
            <b>{title}</b>
            {line && <p>{line}</p>}
        </div>
    );
}

/** A table inset in its card, inside a frame of its own. */
export function TableFrame({ children }: { children: ReactNode }) {
    return (
        <div className="ptr-table-frame">
            <table className="ptr-table">{children}</table>
        </div>
    );
}

/** A loading row of the same height as the content it stands in for. */
export function Loading({ label = 'Loading…' }: { label?: string }) {
    return (
        <div className="ptr-loading" role="status">
            <span className="spinner-border spinner-border-sm" aria-hidden="true" />
            {label}
        </div>
    );
}

/** "1 … 4 5 6 … 12" */
function pages(current: number, total: number): (number | 'gap')[] {
    if (total <= 5) {
        return Array.from({ length: total }, (_, index) => index + 1);
    }

    const shown = new Set([1, total, current, current - 1, current + 1].filter((n) => n >= 1 && n <= total));
    const sorted = [...shown].sort((a, b) => a - b);
    const out: (number | 'gap')[] = [];

    sorted.forEach((page, index) => {
        if (index > 0 && page - sorted[index - 1] > 1) out.push('gap');
        out.push(page);
    });

    return out;
}

/**
 * Compact paging, sized for a narrow column as well as a full card.
 *
 * The buttons are the DataTable's own (`.dt-page`), so paging looks the same
 * here as in every table in the product.
 */
export function Pager({
    page,
    pageCount,
    onChange,
    perPage,
    onPerPage,
    total,
}: {
    page: number;
    pageCount: number;
    onChange: (page: number) => void;
    perPage?: number;
    onPerPage?: (perPage: number) => void;
    /** Printed as "Showing 1–10 of 42" where there is room for it. */
    total?: number;
}) {
    if (pageCount <= 1 && !onPerPage) return null;

    const from = perPage && total ? (page - 1) * perPage + 1 : null;
    const to = perPage && total ? Math.min(page * perPage, total) : null;

    return (
        <div className="ptr-pager">
            {from !== null && total ? (
                <span className="ptr-pager-info">
                    Showing {from}–{to} of {total}
                </span>
            ) : null}

            <nav className="ptr-pager-pages" aria-label="Pagination">
                <button
                    type="button"
                    className="dt-page"
                    aria-label="Previous page"
                    disabled={page <= 1}
                    onClick={() => onChange(page - 1)}
                >
                    <i className="ti ti-chevron-left" />
                </button>

                {pages(page, Math.max(1, pageCount)).map((entry, index) =>
                    entry === 'gap' ? (
                        <span key={`gap-${index}`} className="ptr-pager-gap">
                            …
                        </span>
                    ) : (
                        <button
                            key={entry}
                            type="button"
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

            {onPerPage && perPage && (
                <select
                    className="form-select form-select-sm ptr-pager-size"
                    value={perPage}
                    onChange={(event) => onPerPage(Number(event.target.value))}
                    aria-label="Rows per page"
                >
                    {[10, 20, 50].map((size) => (
                        <option key={size} value={size}>
                            {size} / page
                        </option>
                    ))}
                </select>
            )}
        </div>
    );
}
