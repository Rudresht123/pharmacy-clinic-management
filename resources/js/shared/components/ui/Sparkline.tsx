/**
 * A line small enough to sit inside a row of text.
 *
 * No axes, no labels, no hover: a sparkline carries shape only, and the
 * number beside it carries size. Anything more turns a glance into a chart
 * and defeats the point of putting it in a row.
 *
 * Unlike AreaChart this stretches to whatever box it is given — there are no
 * dots to distort, and the stroke is held at one width by
 * vector-effect="non-scaling-stroke".
 */
export function Sparkline({ points, height = 34 }: { points: number[]; height?: number }) {
    if (points.length < 2) {
        return <span className="spark is-flat" style={{ height }} aria-hidden="true" />;
    }

    // A flat series still needs a line down the middle rather than one
    // pinned to the floor, which would read as zero throughout.
    const peak = Math.max(...points);
    const span = peak === 0 ? 1 : peak;

    const coords = points.map((value, index) => ({
        x: (index / (points.length - 1)) * 100,
        y: peak === 0 ? 50 : 100 - (value / span) * 92 - 4,
    }));

    const line = coords.map((c, i) => `${i === 0 ? 'M' : 'L'}${c.x} ${c.y}`).join(' ');

    return (
        <svg
            className="spark"
            style={{ height }}
            viewBox="0 0 100 100"
            preserveAspectRatio="none"
            aria-hidden="true"
        >
            <path className="spark-area" d={`${line} L100 100 L0 100 Z`} />
            <path className="spark-line" d={line} vectorEffect="non-scaling-stroke" />
        </svg>
    );
}
