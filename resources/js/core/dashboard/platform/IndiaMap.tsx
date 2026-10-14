import { useId, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import type { LocationPlace } from '../platform-api';
import { regionOf } from './regions';

/**
 * India, drawn from its official boundary, with every located organization
 * pinned at its exact latitude and longitude.
 *
 * Drawn rather than tiled on purpose: a street map zoomed out to the whole
 * country makes India small, fills half the card with its neighbours, and
 * draws the de facto line in Kashmir rather than India's boundary. The
 * street map lives where street detail is needed — the organization form,
 * where the pin is placed on the building.
 *
 * The outline is a simplified trace — about 190 points, enough at card size.
 * Longitude is squeezed by cos 22° so the country is not stretched sideways;
 * pins are projected the same way, so they land where they really are.
 */

const OUTLINE: [number, number][] = [
    // Sir Creek, then north along the Pakistan border.
    [68.2, 23.7], [68.75, 24.3], [69.6, 24.25], [70.5, 24.35], [71.1, 24.45], [70.85, 25.1],
    [70.35, 25.7], [70.1, 26.4], [69.55, 26.75], [69.6, 27.2], [70.3, 27.85], [70.9, 27.9],
    [71.6, 28.0], [72.3, 28.75], [72.95, 29.45], [73.4, 29.95], [73.95, 30.4], [74.6, 30.95],
    [74.55, 31.6], [74.95, 32.05], [74.65, 32.5], [74.0, 32.95], [73.55, 33.6], [73.35, 34.3],
    [73.2, 35.0], [72.6, 35.6], [72.55, 36.35], [73.6, 36.85], [74.8, 37.05],
    // Across the top of Kashmir and Ladakh.
    [75.6, 36.75], [76.3, 36.4], [77.1, 35.9], [77.85, 35.5], [78.9, 35.65], [79.9, 35.55],
    [80.3, 35.15], [79.85, 34.45], [79.3, 33.7], [79.45, 32.7], [78.75, 32.55], [78.4, 32.1],
    [78.8, 31.35], [79.45, 31.0], [80.1, 30.65], [80.95, 30.2],
    // Round Nepal.
    [80.6, 29.6], [80.15, 28.85], [80.9, 28.45], [81.7, 27.95], [82.6, 27.45], [83.4, 27.4],
    [84.1, 27.45], [84.65, 27.05], [85.5, 26.75], [86.3, 26.55], [87.25, 26.4], [88.05, 26.4],
    // Sikkim, then under Bhutan.
    [88.15, 27.2], [88.1, 27.9], [88.6, 28.1], [88.95, 27.75], [88.8, 27.1], [89.2, 26.85],
    [89.9, 26.72], [90.7, 26.8], [91.6, 26.82], [92.1, 26.9],
    // Arunachal Pradesh and down the Myanmar border.
    [91.65, 27.5], [91.75, 27.85], [92.6, 27.95], [93.4, 28.6], [94.4, 29.25], [95.3, 29.05],
    [96.1, 29.45], [96.6, 28.75], [97.4, 28.25], [96.95, 27.65], [96.2, 27.3], [95.5, 26.85],
    [95.2, 26.1], [94.75, 25.5], [94.6, 24.75], [94.15, 23.9], [93.4, 23.85], [93.35, 23.0],
    [93.15, 22.2], [92.65, 21.95],
    // Back round Bangladesh: Mizoram, Tripura, Meghalaya, the Siliguri corridor.
    [92.4, 22.75], [92.3, 23.4], [91.75, 22.95], [91.4, 23.2], [91.15, 23.65], [91.35, 24.1],
    [91.9, 24.3], [92.15, 24.5], [92.45, 24.9], [91.85, 25.15], [90.95, 25.18], [90.15, 25.2],
    [89.85, 25.3], [89.85, 25.95], [89.1, 26.2], [88.75, 26.35], [88.4, 26.6], [88.1, 25.95],
    [88.25, 25.3], [88.45, 24.85], [88.15, 24.5], [88.75, 24.2], [88.6, 23.6], [88.85, 22.95],
    [89.0, 22.2], [89.1, 21.65],
    // The east coast to Kanyakumari.
    [88.6, 21.55], [88.15, 21.65], [87.5, 21.6], [86.95, 21.4], [86.75, 20.7], [86.5, 20.15],
    [85.8, 19.75], [85.3, 19.45], [84.75, 19.05], [84.15, 18.3], [83.35, 17.65], [82.45, 17.0],
    [82.3, 16.55], [81.4, 16.3], [81.0, 15.75], [80.3, 15.5], [80.1, 15.0], [80.2, 14.0],
    [80.3, 13.1], [79.95, 12.2], [79.8, 11.4], [79.85, 10.3], [79.3, 10.25], [78.95, 9.6],
    [79.2, 9.25], [78.45, 9.0], [78.1, 8.6], [77.55, 8.08],
    // Up the west coast.
    [77.1, 8.35], [76.6, 8.9], [76.3, 9.6], [76.2, 10.1], [75.9, 10.8], [75.75, 11.3],
    [75.3, 11.95], [74.95, 12.55], [74.8, 13.0], [74.6, 13.7], [74.35, 14.35], [74.05, 14.9],
    [73.75, 15.55], [73.45, 16.3], [73.25, 17.1], [73.0, 17.9], [72.85, 18.75], [72.8, 19.3],
    [72.7, 20.0], [72.85, 20.55], [72.7, 21.1], [72.6, 21.6], [72.65, 22.25],
    // Saurashtra and the Gulf of Kutch.
    [72.3, 21.9], [72.15, 21.6], [71.6, 21.0], [71.0, 20.72], [70.35, 20.9], [69.6, 21.6],
    [69.0, 22.25], [69.1, 22.45], [69.75, 22.4], [70.3, 22.6], [70.55, 22.95], [70.0, 22.95],
    [69.4, 22.85], [68.85, 22.95], [68.6, 23.25], [68.4, 23.55],
];

/*
 * The basemap: major cities and the two seas, so the map reads as a map.
 * Quiet on purpose — grey markers and labels under the pins. `dx`/`dy` nudge
 * each label off its marker, chosen by hand so neighbours never collide.
 */
const CITIES: { name: string; at: [number, number]; dx: number; dy: number; anchor?: 'start' | 'end' | 'middle' }[] = [
    { name: 'Srinagar', at: [74.8, 34.08], dx: 0.45, dy: 0.2 },
    { name: 'Chandigarh', at: [76.78, 30.73], dx: 0.45, dy: -0.15 },
    { name: 'New Delhi', at: [77.21, 28.61], dx: -0.45, dy: -0.25, anchor: 'end' },
    { name: 'Jaipur', at: [75.79, 26.91], dx: -0.45, dy: 0.2, anchor: 'end' },
    { name: 'Lucknow', at: [80.95, 26.85], dx: 0.45, dy: -0.2 },
    { name: 'Patna', at: [85.14, 25.59], dx: 0.45, dy: 0.2 },
    { name: 'Guwahati', at: [91.74, 26.14], dx: 0, dy: 0.85, anchor: 'middle' },
    { name: 'Ahmedabad', at: [72.57, 23.02], dx: 0.45, dy: 0.2 },
    { name: 'Bhopal', at: [77.41, 23.26], dx: 0.45, dy: 0.2 },
    { name: 'Kolkata', at: [88.36, 22.57], dx: 0.45, dy: 0.2 },
    { name: 'Nagpur', at: [79.09, 21.15], dx: 0.45, dy: 0.2 },
    { name: 'Bhubaneswar', at: [85.82, 20.3], dx: 0.45, dy: 0.2 },
    { name: 'Mumbai', at: [72.88, 19.08], dx: -0.45, dy: 0.2, anchor: 'end' },
    { name: 'Hyderabad', at: [78.49, 17.39], dx: 0.45, dy: 0.2 },
    { name: 'Bengaluru', at: [77.59, 12.97], dx: -0.45, dy: 0.2, anchor: 'end' },
    { name: 'Chennai', at: [80.27, 13.08], dx: 0.45, dy: 0.2 },
    { name: 'Kochi', at: [76.27, 9.93], dx: -0.45, dy: 0.2, anchor: 'end' },
];

const SEAS: { name: string; at: [number, number] }[] = [
    { name: 'Arabian Sea', at: [70.6, 15.2] },
    { name: 'Bay of Bengal', at: [87.6, 15.8] },
];

const SQUEEZE = Math.cos((22 * Math.PI) / 180);
const WEST = 68;
const NORTH = 37.3;

function project([lon, lat]: [number, number]): [number, number] {
    return [(lon - WEST) * SQUEEZE, NORTH - lat];
}

const PATH =
    OUTLINE.map((point, index) => {
        const [x, y] = project(point);

        return `${index === 0 ? 'M' : 'L'}${x.toFixed(2)} ${y.toFixed(2)}`;
    }).join(' ') + ' Z';

const VIEW = { width: (97.6 - WEST) * SQUEEZE, height: NORTH - 7.8 };
const PAD = 0.4;

const PRECISION: Record<string, string> = {
    manual: 'Exact — pin placed by hand',
    address: 'From the street address',
    pincode: 'Approximate — PIN code area',
    city: 'Approximate — city only',
};

/** A pin from a lookup that only found the area, not the building. */
const APPROXIMATE = new Set(['pincode', 'city']);

interface Spot {
    key: string;
    xy: [number, number];
    state: string | null;
    places: LocationPlace[];
}

export function IndiaMap({
    places,
    active,
    onActive,
}: {
    places: LocationPlace[];
    /** The state pointed at in the list beside the map. */
    active: string | null;
    onActive: (state: string | null) => void;
}) {
    const navigate = useNavigate();
    const fill = useId().replace(/[^a-zA-Z0-9_-]/g, '');
    const [hovered, setHovered] = useState<string | null>(null);

    // Organizations at the very same point (one PIN code area) share a pin.
    const spots = new Map<string, Spot>();

    for (const place of places) {
        const key = `${place.latitude.toFixed(3)},${place.longitude.toFixed(3)}`;
        const spot = spots.get(key) ?? {
            key,
            xy: project([place.longitude, place.latitude]),
            state: place.state,
            places: [],
        };

        spot.places.push(place);
        spots.set(key, spot);
    }

    const list = [...spots.values()];
    const shown = list.find((spot) => spot.key === hovered);

    return (
        <div className="sa-india" onMouseLeave={() => setHovered(null)}>
            <svg
                viewBox={`${-PAD} ${-PAD} ${VIEW.width + PAD * 2} ${VIEW.height + PAD * 2}`}
                role="img"
                aria-label={`Map of India with ${places.length} organizations: ${places.map((place) => place.name).join(', ')}`}
            >
                <defs>
                    <linearGradient id={`sa-land-${fill}`} x1="0" x2="0" y1="0" y2="1">
                        <stop offset="0%" className="sa-india-land-top" />
                        <stop offset="100%" className="sa-india-land-bottom" />
                    </linearGradient>
                </defs>

                <path className="sa-india-land" d={PATH} style={{ fill: `url(#sa-land-${fill})` }} />

                <g className="sa-india-base" aria-hidden="true">
                    {SEAS.map((sea) => {
                        const [x, y] = project(sea.at);

                        return (
                            <text key={sea.name} className="sa-india-sea" x={x} y={y} textAnchor="middle">
                                {sea.name}
                            </text>
                        );
                    })}

                    {CITIES.map((city) => {
                        const [x, y] = project(city.at);

                        return (
                            <g key={city.name}>
                                <circle className="sa-india-city" cx={x} cy={y} r={0.14} />
                                <text
                                    className="sa-india-city-name"
                                    x={x + city.dx}
                                    y={y + city.dy}
                                    textAnchor={city.anchor ?? 'start'}
                                >
                                    {city.name}
                                </text>
                            </g>
                        );
                    })}
                </g>

                {list.map((spot) => {
                    const color = regionOf(spot.state)?.color ?? 'var(--od-grey)';
                    const approximate = spot.places.every((place) => APPROXIMATE.has(place.source ?? ''));
                    const dimmed = active !== null && spot.state !== active;
                    const r = Math.min(0.42 + (spot.places.length - 1) * 0.12, 0.9);

                    return (
                        <g
                            key={spot.key}
                            className={`sa-india-pin${dimmed ? ' is-dimmed' : ''}${approximate ? ' is-approximate' : ''}${
                                hovered === spot.key ? ' is-active' : ''
                            }`}
                            style={{ color }}
                            onMouseEnter={() => {
                                setHovered(spot.key);
                                onActive(spot.state);
                            }}
                            onMouseLeave={() => onActive(null)}
                            onClick={() => spot.places.length === 1 && navigate(`/organizations/${spot.places[0].uuid}`)}
                        >
                            {/* A soft halo, so even one pin is found at a glance. */}
                            <circle className="sa-india-halo" cx={spot.xy[0]} cy={spot.xy[1]} r={r * 2.2} />
                            <circle className="sa-india-dot" cx={spot.xy[0]} cy={spot.xy[1]} r={r} />
                        </g>
                    );
                })}
            </svg>

            {shown && (
                <div
                    className="od-tip sa-india-tip"
                    role="status"
                    style={{
                        left: `${((shown.xy[0] + PAD) / (VIEW.width + PAD * 2)) * 100}%`,
                        top: `${((shown.xy[1] + PAD) / (VIEW.height + PAD * 2)) * 100}%`,
                    }}
                >
                    {shown.places.slice(0, 5).map((place) => (
                        <b key={place.uuid}>{place.name}</b>
                    ))}
                    {shown.places.length > 5 && <span>+{shown.places.length - 5} more</span>}
                    <span>{[shown.places[0].city, shown.state].filter(Boolean).join(', ')}</span>
                    <em>{PRECISION[shown.places[0].source ?? ''] ?? ''}</em>
                </div>
            )}
        </div>
    );
}
