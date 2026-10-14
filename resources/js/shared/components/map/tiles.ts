import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

/*
 * The street map every map in the app is drawn on: OpenStreetMap's own
 * tiles — colourful, with place names at every zoom, and keyless. (CARTO's
 * basemaps were the first choice; they now answer "API key required".)
 *
 * Free under OpenStreetMap's tile policy, which asks for the attribution
 * below and light use — a handful of admins looking at a dashboard is.
 * There is no keyless dark style, so dark mode recolours these tiles with
 * a CSS filter instead (maps.css, `.is-dark-tiles`).
 */
const TILES = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';

const ATTRIBUTION =
    '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noreferrer">OpenStreetMap</a> contributors';

export function baseTiles(theme: 'light' | 'dark'): L.TileLayer {
    return L.tileLayer(TILES, {
        attribution: ATTRIBUTION,
        maxZoom: 19,
        className: theme === 'dark' ? 'is-dark-tiles' : '',
    });
}

/** India, edge to edge — what a picker with no pin yet opens on. */
export const INDIA_BOUNDS: L.LatLngBoundsExpression = [
    [6.5, 68.0],
    [37.1, 97.4],
];

/** How close to zoom on a point, by how much it can be trusted. */
export function zoomFor(source: string | null | undefined): number {
    switch (source) {
        case 'manual':
        case 'address':
            return 16;
        case 'pincode':
            return 14;
        default:
            return 12;
    }
}

/** A teardrop pin drawn in CSS, so no marker images have to be bundled. */
export function pinIcon(): L.DivIcon {
    return L.divIcon({
        className: 'lp-pin',
        html: '<span></span>',
        iconSize: [30, 40],
        iconAnchor: [15, 38],
    });
}
