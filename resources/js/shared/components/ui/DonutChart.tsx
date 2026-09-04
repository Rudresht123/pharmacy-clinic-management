import { useState } from 'react';

export interface DonutSlice {
    /** Stable key; also the legend text. */
    label: string;
    value: number;
    /**
     * A slice that is an absence rather than a category — "Not recorded".
     * Drawn grey, and never given a categorical hue.
     */
    muted?: boolean;
}

/** Geometry, in the 100×100 viewBox the SVG is drawn in. */
const RADIUS = 38;
const THICKNESS = 15;
const CIRCUMFERENCE = 2 * Math.PI * RADIUS;

/** The surface gap between slices, in the same units. */
const GAP = 1.4;

/** How many hues exist before the tail has to fold into "Other". */
const HUES = 6;

/**
 * A ring showing how a total divides.
 *
 * A caveat worth knowing: length along a shared baseline is read more
 * accurately than angle, so a stacked bar beats a ring whenever slices are
 * close in size or numerous. This is the case where a ring is fine — a
 * handful of named branches, each labelled with its own count and share —
 * and the ring earns its place by putting the total in the middle, which a
 * bar has nowhere to put.
 *
 * Past six categories the tail folds into "Other" rather than growing new
 * hues: a seventh generated colour is indistinguishable from an existing
 * one under colour blindness, and a ring of nine slivers answers nothing.
 */
export function DonutChart({
    slices,
    centreLabel = 'Total',
    empty = 'Nothing to show yet.',
}: {
    slices: DonutSlice[];
    /** Sits under the total in the middle of the ring. */
    centreLabel?: string;
    empty?: string;
}) {
    const [hovered, setHovered] = useState<string | null>(null);

    const total = slices.reduce((sum, slice) => sum + slice.value, 0);

    if (total === 0) {
        return <p className="bar-list-empty">{empty}</p>;
    }

    const present = slices.filter((slice) => slice.value > 0);

    // Biggest first, with absences always last however large they are.
    const ranked = [...present].sort((a, b) => {
        if (Boolean(a.muted) !== Boolean(b.muted)) {
            return a.muted ? 1 : -1;
        }

        return b.value - a.value;
    });

    const named = ranked.filter((slice) => !slice.muted);
    const tail = named.slice(HUES);

    const shown: DonutSlice[] = [
        ...named.slice(0, HUES),
        ...(tail.length > 0
            ? [
                  {
                      label: `${tail.length} more`,
                      value: tail.reduce((sum, slice) => sum + slice.value, 0),
                      muted: true,
                  },
              ]
            : []),
        ...ranked.filter((slice) => slice.muted),
    ];

    /*
     * Hues follow the entity, in rank order, and are assigned before any
     * hover state exists — so highlighting a slice never repaints the rest.
     */
    let hue = 0;
    let offset = 0;

    // A ring with one slice has no neighbour to be separated from, and a
    // notch cut into a complete circle reads as a rendering fault.
    const gap = shown.length > 1 ? GAP : 0;

    const arcs = shown.map((slice) => {
        const share = slice.value / total;
        const length = share * CIRCUMFERENCE;
        // The gap is the surface showing through between neighbours; a
        // stroke around each arc would add ink that is not data.
        const drawn = Math.max(length - gap, 0.6);

        const arc = {
            ...slice,
            share,
            hue: slice.muted ? null : (hue++ % HUES) + 1,
            dash: `${drawn} ${CIRCUMFERENCE - drawn}`,
            offset: -offset,
        };

        offset += length;

        return arc;
    });

    const active = arcs.find((arc) => arc.label === hovered);

    return (
        <div className="donut">
            <div className="donut-plot">
                <svg viewBox="0 0 100 100" role="img" aria-hidden="true">
                    {/* Starts the ring at twelve o'clock rather than three. */}
                    <g transform="rotate(-90 50 50)">
                        {arcs.map((arc) => (
                            <circle
                                key={arc.label}
                                className={`donut-arc${arc.muted ? ' is-muted' : ''}${
                                    hovered && hovered !== arc.label ? ' is-dimmed' : ''
                                }`}
                                cx="50"
                                cy="50"
                                r={RADIUS}
                                fill="none"
                                strokeWidth={THICKNESS}
                                strokeDasharray={arc.dash}
                                strokeDashoffset={arc.offset}
                                style={arc.hue ? { stroke: `var(--cat-${arc.hue})` } : undefined}
                                onMouseEnter={() => setHovered(arc.label)}
                                onMouseLeave={() => setHovered(null)}
                            />
                        ))}
                    </g>
                </svg>

                {/*
                 * The hole is not decoration — it is where the figure goes,
                 * and where a hovered slice reports itself. That is why this
                 * needs no floating tooltip: nothing has to be positioned
                 * against an arc.
                 */}
                <div className="donut-centre" role="status">
                    <b>{active ? active.value : total}</b>
                    <span>
                        {active
                            ? `${active.label} · ${Math.round(active.share * 100)}%`
                            : centreLabel}
                    </span>
                </div>
            </div>

            <ul className="donut-legend">
                {arcs.map((arc) => (
                    <li
                        key={arc.label}
                        className={hovered === arc.label ? 'is-active' : undefined}
                        onMouseEnter={() => setHovered(arc.label)}
                        onMouseLeave={() => setHovered(null)}
                    >
                        <span
                            className={`donut-key${arc.muted ? ' is-muted' : ''}`}
                            style={arc.hue ? { background: `var(--cat-${arc.hue})` } : undefined}
                            aria-hidden="true"
                        />
                        <span className="donut-name" title={arc.label}>
                            {arc.label}
                        </span>
                        <span className="donut-value">{arc.value}</span>
                        <span className="donut-share">{Math.round(arc.share * 100)}%</span>
                    </li>
                ))}
            </ul>
        </div>
    );
}
