import { Sparkline } from './Sparkline';

export interface TrendRow {
    label: string;
    /** The whole window's figure — the sparkline carries shape, this carries size. */
    total: number;
    points: number[];
    /** An absence rather than a real subject; drawn greyer. */
    muted?: boolean;
}

/**
 * Small multiples: one tiny chart per thing, stacked.
 *
 * The alternative — every branch as a line on one axis — would flatten a
 * quiet branch against a busy one and need a legend to tell four
 * near-identical lines apart. A row each compares shapes honestly, and each
 * row's own total says how big it is.
 *
 * Each sparkline is scaled to itself. That is the trade small multiples
 * make: shape is comparable, height is not, which is exactly why the number
 * sits beside it.
 */
export function TrendRows({
    rows,
    caption,
    empty = 'Nothing to show yet.',
}: {
    rows: TrendRow[];
    caption?: string;
    empty?: string;
}) {
    if (rows.length === 0) {
        return <p className="bar-list-empty">{empty}</p>;
    }

    return (
        <div className="trend-rows">
            {caption && <p className="area-caption">{caption}</p>}

            {rows.map((row) => (
                <div className={`trend-row${row.muted ? ' is-muted' : ''}`} key={row.label}>
                    <span className="trend-row-label" title={row.label}>
                        {row.label}
                    </span>

                    <span className="trend-row-plot">
                        <Sparkline points={row.points} />
                    </span>

                    <span className="trend-row-value">{row.total}</span>
                </div>
            ))}
        </div>
    );
}
