import { useState } from 'react';
import { Avatar } from '@/shared/components/ui/Avatar';
import { SearchableSelect } from '@/shared/components/form/SearchableSelect';
import { cn } from '@/shared/utils/cn';
import type { Page } from '@/shared/api/resource';
import { capitalise, day, mobile } from '../labels';
import type { BranchOption, PatientRow } from '../types';
import { Loading, Pager } from './Bits';

export interface ListFilters {
    search: string;
    registered_location_id: string;
    gender: string;
    status: string;
    page: number;
    per_page: number;
}

const GENDERS = [
    { value: '', label: 'All' },
    { value: 'male', label: 'Male' },
    { value: 'female', label: 'Female' },
    { value: 'other', label: 'Other' },
];

const STATUSES = [
    { value: '', label: 'All' },
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
];

const GENDER_ICONS: Record<string, string> = {
    male: 'ti ti-gender-male',
    female: 'ti ti-gender-female',
    other: 'ti ti-gender-agender',
};

/**
 * The last visit, as short as it can be said: "Today", "3d ago", "12 Aug".
 *
 * Short because it sits in the corner of a narrow row; the full date is in
 * the tooltip.
 */
function lastSeen(value: string | null | undefined): { text: string; tone: 'today' | 'recent' | 'past' } | null {
    if (!value) return null;

    const [y, m, d] = value.slice(0, 10).split('-').map(Number);
    const then = new Date(y, m - 1, d);

    if (Number.isNaN(then.getTime())) return null;

    const today = new Date();
    today.setHours(0, 0, 0, 0);

    const days = Math.round((today.getTime() - then.getTime()) / 86_400_000);

    if (days === 0) return { text: 'Today', tone: 'today' };
    if (days === 1) return { text: 'Yesterday', tone: 'recent' };
    if (days > 1 && days < 7) return { text: `${days}d ago`, tone: 'recent' };

    return {
        text: then.toLocaleDateString('en-GB', {
            day: 'numeric',
            month: 'short',
            ...(then.getFullYear() !== today.getFullYear() ? { year: '2-digit' } : {}),
        }),
        tone: 'past',
    };
}

/**
 * The register, searchable and paged — the left half of the screen.
 *
 * The same patients as the Patients screen, one row per person for the whole
 * organization; the branch filter is where they registered, the last visit is
 * the latest one at a branch this reader can see.
 *
 * The filters fold away behind one button so the list starts higher, and what
 * is switched on shows as chips that each take it off again. The branch only
 * appears — as a filter and on each row — where there is more than one.
 */
export function PatientList({
    page,
    loading,
    fetching,
    filters,
    onFilter,
    branches,
    selected,
    onSelect,
    label,
}: {
    page: Page<PatientRow> | undefined;
    loading: boolean;
    fetching: boolean;
    filters: ListFilters;
    onFilter: (next: Partial<ListFilters>) => void;
    branches: BranchOption[];
    selected: number | null;
    onSelect: (id: number) => void;
    /** What this organization calls a patient, plural. */
    label: string;
}) {
    const [filtersOpen, setFiltersOpen] = useState(false);

    const rows = page?.data ?? [];
    const total = page?.meta.total;
    const manyBranches = branches.length > 1;

    const branchName = branches.find((branch) => String(branch.id) === filters.registered_location_id)?.name;

    // What is narrowing the list right now, each with the change that undoes it.
    const active = [
        branchName && { key: 'branch', label: branchName, clear: { registered_location_id: '' } },
        filters.gender && { key: 'gender', label: capitalise(filters.gender), clear: { gender: '' } },
        filters.status && { key: 'status', label: capitalise(filters.status), clear: { status: '' } },
    ].filter(Boolean) as { key: string; label: string; clear: Partial<ListFilters> }[];

    return (
        <aside className="ptr-card ptr-list" aria-label={`All ${label}`}>
            <header className="ptr-list-head">
                <div className="ptr-list-title">
                    <h2>All {label}</h2>
                    {total !== undefined && <span className="ptr-count">{total.toLocaleString('en-IN')}</span>}
                </div>

                <div className="ptr-search-row">
                    <label className="ptr-search">
                        <i className="ti ti-search" aria-hidden="true" />
                        <input
                            type="search"
                            value={filters.search}
                            onChange={(event) => onFilter({ search: event.target.value, page: 1 })}
                            placeholder="Search name, UHID, mobile…"
                            aria-label="Search patients"
                        />
                    </label>

                    <button
                        type="button"
                        className={cn('ptr-filter-btn', (filtersOpen || active.length > 0) && 'is-on')}
                        onClick={() => setFiltersOpen((open) => !open)}
                        aria-expanded={filtersOpen}
                        aria-controls="ptr-filter-panel"
                        title="Filters"
                    >
                        <i className="ti ti-adjustments-horizontal" aria-hidden="true" />
                        {active.length > 0 && <span className="ptr-filter-count">{active.length}</span>}
                    </button>
                </div>

                {filtersOpen && (
                    <div className="ptr-filter-panel" id="ptr-filter-panel">
                        {manyBranches && (
                            <div className="ptr-filter-field">
                                <span>Branch</span>
                                <SearchableSelect
                                    value={filters.registered_location_id}
                                    onChange={(value) => onFilter({ registered_location_id: value, page: 1 })}
                                    options={branches.map((branch) => ({
                                        value: String(branch.id),
                                        label: branch.name,
                                    }))}
                                    placeholder="All branches"
                                    ariaLabel="Branch"
                                    clearable
                                    compact
                                />
                            </div>
                        )}

                        <div className="ptr-filter-field">
                            <span>Gender</span>
                            <div className="ptr-seg ptr-seg-fill" role="radiogroup" aria-label="Gender">
                                {GENDERS.map((option) => (
                                    <button
                                        key={option.value || 'all'}
                                        type="button"
                                        role="radio"
                                        aria-checked={filters.gender === option.value}
                                        className={cn(filters.gender === option.value && 'is-on')}
                                        onClick={() => onFilter({ gender: option.value, page: 1 })}
                                    >
                                        {option.label}
                                    </button>
                                ))}
                            </div>
                        </div>

                        <div className="ptr-filter-field">
                            <span>Status</span>
                            <div className="ptr-seg ptr-seg-fill" role="radiogroup" aria-label="Status">
                                {STATUSES.map((option) => (
                                    <button
                                        key={option.value || 'all'}
                                        type="button"
                                        role="radio"
                                        aria-checked={filters.status === option.value}
                                        className={cn(filters.status === option.value && 'is-on')}
                                        onClick={() => onFilter({ status: option.value, page: 1 })}
                                    >
                                        {option.label}
                                    </button>
                                ))}
                            </div>
                        </div>
                    </div>
                )}

                {active.length > 0 && (
                    <div className="ptr-chips">
                        {active.map((chip) => (
                            <button
                                key={chip.key}
                                type="button"
                                className="ptr-chip"
                                onClick={() => onFilter({ ...chip.clear, page: 1 })}
                                title={`Remove ${chip.label}`}
                            >
                                {chip.label}
                                <i className="ti ti-x" aria-hidden="true" />
                            </button>
                        ))}

                        {active.length > 1 && (
                            <button
                                type="button"
                                className="ptr-chip-clear"
                                onClick={() => onFilter({ registered_location_id: '', gender: '', status: '', page: 1 })}
                            >
                                Clear all
                            </button>
                        )}
                    </div>
                )}
            </header>

            <div className={cn('ptr-list-body', fetching && !loading && 'is-refreshing')}>
                {loading ? (
                    <Loading label="Loading patients…" />
                ) : rows.length === 0 ? (
                    <div className="ptr-list-none">
                        <i className="ti ti-user-search" aria-hidden="true" />
                        <b>No patients found</b>
                        <p>
                            {filters.search
                                ? `Nobody matches “${filters.search}”.`
                                : 'Nobody matches these filters.'}
                        </p>
                    </div>
                ) : (
                    <ul className="ptr-rows">
                        {rows.map((row) => {
                            const seen = lastSeen(row.last_visit_on);
                            const phone = mobile(row.phone);

                            return (
                                <li key={row.id}>
                                    <button
                                        type="button"
                                        className={cn(
                                            'ptr-row',
                                            selected === row.id && 'is-on',
                                            !row.is_active && 'is-inactive',
                                        )}
                                        aria-current={selected === row.id ? 'true' : undefined}
                                        onClick={() => onSelect(row.id)}
                                    >
                                        <span className={cn('ptr-row-face', row.gender && `is-${row.gender}`)}>
                                            <Avatar name={row.name} src={row.photo_url} fallback="silhouette" caption={row.code} />
                                        </span>

                                        <span className="ptr-row-who">
                                            <span className="ptr-row-top">
                                                <b className="ptr-row-name">{row.name}</b>

                                                {seen ? (
                                                    <span
                                                        className={`ptr-row-seen is-${seen.tone}`}
                                                        title={`Last visit ${day(row.last_visit_on)}`}
                                                    >
                                                        {seen.text}
                                                    </span>
                                                ) : (
                                                    row.last_visit_on === null && (
                                                        <span className="ptr-row-seen is-none" title="Never visited">
                                                            No visits
                                                        </span>
                                                    )
                                                )}
                                            </span>

                                            <small className="ptr-row-meta">
                                                <span className="ptr-row-code">{row.code ?? '—'}</span>
                                                {row.age != null && <span>{row.age} yrs</span>}
                                                {row.gender && (
                                                    <span className={`ptr-row-sex is-${row.gender}`}>
                                                        <i className={GENDER_ICONS[row.gender]} aria-hidden="true" />
                                                        {capitalise(row.gender)}
                                                    </span>
                                                )}
                                                {!row.is_active && <span className="ptr-row-off">Inactive</span>}
                                            </small>

                                            <small className="ptr-row-line">
                                                <span>
                                                    <i className="ti ti-phone" aria-hidden="true" />
                                                    {phone ?? 'No mobile'}
                                                </span>

                                                {manyBranches && row.registered_location?.name && (
                                                    <span className="ptr-row-branch" title="Registered branch">
                                                        <i className="ti ti-building-hospital" aria-hidden="true" />
                                                        {row.registered_location.name}
                                                    </span>
                                                )}
                                            </small>
                                        </span>
                                    </button>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </div>

            {page && page.meta.total > 0 && (
                <footer className="ptr-list-foot">
                    <Pager
                        page={page.meta.current_page}
                        pageCount={page.meta.last_page}
                        onChange={(next) => onFilter({ page: next })}
                        perPage={filters.per_page}
                        onPerPage={(perPage) => onFilter({ per_page: perPage, page: 1 })}
                    />
                </footer>
            )}
        </aside>
    );
}
