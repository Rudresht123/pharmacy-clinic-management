import { useEffect, useRef, useState } from 'react';
import L from 'leaflet';
import { useAppSettings } from '@/shared/hooks/useAppSettings';
import { geocodeAddress, type AddressParts, type MapPoint } from '@/shared/geo/geocode';
import { isPincode, type LookupBase } from '@/shared/geo/pincode';
import { baseTiles, INDIA_BOUNDS, pinIcon, zoomFor } from './tiles';

type Status = 'idle' | 'finding' | 'not-found' | 'failed';

const NOTE: Record<string, string> = {
    manual: 'Pin placed by hand.',
    address: 'Found from the address. Drag the pin if it is not exactly on the building.',
    pincode: 'Only the PIN code area was found — drag the pin onto the building for an exact location.',
    city: 'Only the city was found — drag the pin onto the building for an exact location.',
};

/**
 * Where an organization is, as a pin on a real map.
 *
 * The pin finds itself from the address as it is typed — the PIN code is
 * enough to start — and the person can then drag it, or click the map, to
 * put it on the exact building. Once they have, it is theirs: later edits to
 * the address no longer move it, unless they ask with "Find from address".
 */
export function LocationPicker({
    base,
    parts,
    value,
    onChange,
}: {
    /** The platform's lookups or the workspace's. */
    base: LookupBase;
    /** The address as typed so far. */
    parts: AddressParts;
    value: MapPoint | null;
    onChange: (point: MapPoint) => void;
}) {
    const { resolvedTheme } = useAppSettings();

    const box = useRef<HTMLDivElement>(null);
    const map = useRef<L.Map | null>(null);
    const marker = useRef<L.Marker | null>(null);
    const tiles = useRef<L.TileLayer | null>(null);

    // Leaflet's handlers outlive renders; these keep them reading the latest props.
    const onChangeRef = useRef(onChange);
    onChangeRef.current = onChange;

    const [status, setStatus] = useState<Status>('idle');

    // Every lookup is numbered, so a slow answer never overrides a newer one.
    const asked = useRef(0);

    /* --- the map, once ------------------------------------------------ */

    useEffect(() => {
        if (!box.current) return;

        const created = L.map(box.current, {
            // A page scroll passing over the map must not zoom it.
            scrollWheelZoom: false,
        });

        if (value) {
            created.setView([value.latitude, value.longitude], zoomFor(value.source));
        } else {
            created.fitBounds(INDIA_BOUNDS);
        }

        created.on('click', (event: L.LeafletMouseEvent) => {
            onChangeRef.current({ latitude: event.latlng.lat, longitude: event.latlng.lng, source: 'manual' });
        });

        map.current = created;

        // A map drawn into a box that later changes size shows grey tiles.
        const observer = new ResizeObserver(() => created.invalidateSize());
        observer.observe(box.current);

        return () => {
            observer.disconnect();
            created.remove();
            map.current = null;
            marker.current = null;
            tiles.current = null;
        };
        // Built once; the effects below keep it current.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    /* --- tiles follow the theme --------------------------------------- */

    useEffect(() => {
        const current = map.current;

        if (!current) return;

        tiles.current?.remove();
        tiles.current = baseTiles(resolvedTheme).addTo(current);
    }, [resolvedTheme]);

    /* --- the pin follows the value ------------------------------------ */

    useEffect(() => {
        const current = map.current;

        if (!current) return;

        if (!value) {
            marker.current?.remove();
            marker.current = null;

            return;
        }

        const at: L.LatLngTuple = [value.latitude, value.longitude];

        if (!marker.current) {
            const pin = L.marker(at, { draggable: true, icon: pinIcon(), autoPan: true, keyboard: true });

            pin.on('dragend', () => {
                const moved = pin.getLatLng();

                onChangeRef.current({ latitude: moved.lat, longitude: moved.lng, source: 'manual' });
            });

            marker.current = pin.addTo(current);
        } else {
            marker.current.setLatLng(at);
        }

        // A found point is flown to; a hand-placed one is already where they are looking.
        if (value.source !== 'manual') {
            current.setView(at, zoomFor(value.source));
        }
    }, [value?.latitude, value?.longitude, value?.source]); // eslint-disable-line react-hooks/exhaustive-deps

    /* --- finding the pin from the address ----------------------------- */

    const searchable = isPincode(parts.pincode ?? '') || Boolean(parts.city?.trim());
    const key = [parts.address, parts.city, parts.state, parts.pincode].map((part) => (part ?? '').trim()).join('|');

    async function find() {
        const ticket = ++asked.current;

        setStatus('finding');

        try {
            const point = await geocodeAddress(parts, base);

            if (ticket !== asked.current) return;

            if (!point) {
                setStatus('not-found');

                return;
            }

            setStatus('idle');
            onChangeRef.current(point);
        } catch {
            if (ticket === asked.current) setStatus('failed');
        }
    }

    /*
     * Look the address up a moment after typing stops — never over a pin
     * somebody placed by hand, and not when a saved record is merely being
     * filled in: the first address a form shows, with a pin already there,
     * is the record arriving, and its pin is already right.
     */
    const lastKey = useRef<string | null>(null);

    useEffect(() => {
        const previous = lastKey.current;
        lastKey.current = key;

        if (!searchable || value?.source === 'manual') return;
        if (value && (previous === null || previous.replace(/\|/g, '') === '')) return;

        const timer = window.setTimeout(() => void find(), 900);

        return () => window.clearTimeout(timer);
    }, [key, searchable]); // eslint-disable-line react-hooks/exhaustive-deps

    const note =
        status === 'finding'
            ? 'Finding the address on the map…'
            : status === 'not-found'
              ? 'This address was not found on the map — click the map or drag the pin to place it.'
              : status === 'failed'
                ? 'The map service did not answer — click the map to place the pin by hand.'
                : value
                  ? NOTE[value.source]
                  : searchable
                    ? 'Click the map to place the pin.'
                    : 'Enter the PIN code to find the location, or click the map to place the pin.';

    return (
        <div className="lp">
            <div className="lp-map" ref={box} role="application" aria-label="Map — click to place the pin, drag it to adjust" />

            <div className="lp-foot">
                <span className={`lp-note${status === 'failed' || status === 'not-found' ? ' is-warn' : ''}`}>
                    <i
                        className={status === 'finding' ? 'ti ti-loader-2 lp-spin' : 'ti ti-map-pin'}
                        aria-hidden="true"
                    />
                    {note}
                </span>

                <span className="lp-actions">
                    {value && (
                        <span className="lp-coords" title="Latitude, longitude">
                            {value.latitude.toFixed(5)}, {value.longitude.toFixed(5)}
                        </span>
                    )}

                    {searchable && (
                        <button type="button" className="lp-find" onClick={() => void find()} disabled={status === 'finding'}>
                            <i className="ti ti-current-location" aria-hidden="true" />
                            Find from address
                        </button>
                    )}
                </span>
            </div>
        </div>
    );
}
