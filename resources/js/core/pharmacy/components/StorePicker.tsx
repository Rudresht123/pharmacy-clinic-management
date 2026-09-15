import { useSearchParams, Link } from 'react-router-dom';
import { SearchableSelect } from '@/shared/components/form/SearchableSelect';
import { storesHooks } from '../api';
import type { PharmacyStore } from '../types';

/**
 * Which store a stock screen is about.
 *
 * Kept in the URL (?store=12), so a link to one store's stock opens on that
 * store and Back returns to the one somebody was looking at. Defaults to the
 * branch's default store — the list comes back default first.
 */
export function useChosenStore() {
    const [params, setParams] = useSearchParams();

    // Every operational store the person can use, default stores first.
    const { data, isLoading } = storesHooks.useList({ all: 1 });
    const stores = data ?? [];

    const asked = Number(params.get('store'));
    const store = stores.find((candidate) => candidate.id === asked) ?? stores[0];

    function choose(id: number) {
        const next = new URLSearchParams(params);
        next.set('store', String(id));
        setParams(next, { replace: true });
    }

    return { stores, store, choose, isLoading };
}

export function StorePicker({
    stores,
    value,
    onChange,
}: {
    stores: PharmacyStore[];
    value: PharmacyStore | undefined;
    onChange: (id: number) => void;
}) {
    return (
        <div className="ph-store-picker">
            <label className="form-label mb-1" htmlFor="store-picker">
                Store
            </label>
            <SearchableSelect
                id="store-picker"
                value={value ? String(value.id) : ''}
                onChange={(id) => onChange(Number(id))}
                options={stores.map((store) => ({
                    value: String(store.id),
                    label: store.is_default ? `${store.name} (default)` : store.name,
                    hint: store.location_name ?? undefined,
                }))}
                placeholder="Choose a store"
            />
        </div>
    );
}

/** What a stock screen says when there is no store to show. */
export function NoStores() {
    return (
        <div className="text-center py-5">
            <i className="ti ti-building-warehouse fs-1 text-muted" aria-hidden="true" />
            <p className="mt-2 mb-1 fw-semibold">No store to show</p>
            <p className="text-muted mb-3">
                Stock is kept in a store. Add one for your branch, or ask for access to one.
            </p>
            <Link className="btn btn-primary btn-sm" to="/pharmacy/stores">
                Go to stores
            </Link>
        </div>
    );
}
