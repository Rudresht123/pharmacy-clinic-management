export interface BarListRow {
    /** Shown at the left; also the row's key. */
    label: string;
    value: number;
    /** Rows the reader should read as "no answer" rather than a real category. */
    muted?: boolean;
}

/**
 * A ranked horizontal bar list — the form for "how does this total split
 * across a handful of named things".
 *
 * One series, so no legend: the caption above says what is plotted. Values
 * ride the bar ends, which is the one place a number per row does not become
 * noise, because there are only ever a few rows.
 */
export function BarList({
    rows,
    empty = 'Nothing to show yet.',
    max = 6,
    sort = true,
}: {
    rows: BarListRow[];
    empty?: string;
    /** Longer lists are truncated: a bar list stops being readable past a few. */
    max?: number;
    /**
     * Off for rows that carry their own order — age bands, months, sizes.
     * Ranking an ordinal scale by size destroys the one thing it is for, so
     * the caller says which kind of list this is rather than the component
     * guessing.
     */
    sort?: boolean;
}) {
    const ordered = sort ? [...rows].sort((a, b) => b.value - a.value) : rows;
    const shown = ordered.slice(0, max);
    const rest = ordered.slice(max);

    // Shares are of the largest row, not the total: this compares rows to each
    // other rather than claiming to be a parts-of-whole chart.
    const peak = Math.max(...shown.map((row) => row.value), 1);

    if (rows.length === 0) {
        return <p className="bar-list-empty">{empty}</p>;
    }

    return (
        <div className="bar-list">
            {shown.map((row) => (
                <div className="bar-list-row" key={row.label}>
                    <span className="bar-list-label" title={row.label}>
                        {row.label}
                    </span>

                    <span className="bar-list-track">
                        <span
                            className={`bar-list-fill${row.muted ? ' is-muted' : ''}`}
                            style={{ width: `${Math.max((row.value / peak) * 100, 2)}%` }}
                        />
                    </span>

                    <span className="bar-list-value">{row.value}</span>
                </div>
            ))}

            {rest.length > 0 && (
                <p className="bar-list-more">
                    +{rest.length} more, {rest.reduce((sum, row) => sum + row.value, 0)} between
                    them
                </p>
            )}
        </div>
    );
}
