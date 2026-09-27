import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { getValidationErrors, resolveErrorMessage } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import type { Medicine } from '@/core/medicines/types';
import {
    useCreatePrescription,
    useIssuePrescription,
    useMedicineSearch,
    useStoreAvailability,
    useUpdatePrescription,
    useVisitPrescription,
} from '../api';
import { blankLine, quantityFormula, registerFlush, SLOTS, suggestQuantity, toDraft, toPayload } from '../lines';
import {
    AVAILABILITY_LABELS,
    DURATION_LABELS,
    FOOD_LABELS,
    FREQUENCY_LABELS,
    STATUS_LABELS,
    type Availability,
    type DurationUnit,
    type FoodTiming,
    type Frequency,
    type LineDraft,
    type Prescription,
} from '../types';

/**
 * The units a dose actually comes in — a click, not a typo waiting to
 * happen. `base_unit` has no fixed list in the catalogue (an org can name
 * its own), so whatever a picked medicine already carries is always added
 * to this list too, rather than trusted to already be on it.
 */
const UNIT_OPTIONS = [
    'tablet', 'capsule', 'ml', 'mg', 'drop', 'tube', 'sachet',
    'vial', 'injection', 'application', 'puff', 'strip', 'spoon',
];

/** What a named frequency means in practice — read under the picker. */
const FREQUENCY_HINTS: Record<Frequency, string> = {
    od: 'Once, in the morning',
    bd: 'Morning and night',
    tds: 'Morning, afternoon and night',
    qid: 'Four times through the day',
    hs: 'At night, before bed',
    sos: 'Only when needed',
    stat: 'Right away, just once',
    weekly: 'The same day each week',
    custom: 'As directed, in person',
};

/** The fields a line's errors can name, other than which medicine it is. */
const LINE_FIELDS = [
    'dose_amount',
    'dose_unit',
    ...SLOTS,
    'frequency',
    'food_timing',
    'duration',
    'duration_unit',
    'prescribed_quantity',
    'instructions',
];

/** "12 Mar 2027" */
function shortDate(value: string | null): string {
    if (!value) return '—';

    const [year, month, day] = value.split('-').map(Number);

    return new Date(year, month - 1, day).toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

/** Stock at the visit's store, as a warning — never a refusal. */
function StockChip({ stock }: { stock?: Availability }) {
    if (!stock) return null;

    const count =
        stock.status === 'available' || stock.status === 'low_stock' ? ` · ${stock.dispensable}` : '';

    return (
        <span
            className={`rx-stock is-${stock.status}`}
            title={stock.nearest_expiry ? `Nearest expiry ${shortDate(stock.nearest_expiry)}` : undefined}
        >
            {AVAILABILITY_LABELS[stock.status]}
            {count}
        </span>
    );
}

/**
 * Search the catalogue, or keep what was typed as an unlisted medicine.
 *
 * The list is rendered into `document.body`, fixed to the viewport: the
 * consultation card clips its overflow, and a list inside it would be cut
 * off at the card's edge.
 */
function MedicinePicker({
    line,
    storeId,
    invalid,
    onPick,
    onType,
    onClear,
}: {
    line: LineDraft;
    storeId: number | null;
    invalid: boolean;
    onPick: (medicine: Medicine) => void;
    onType: (name: string) => void;
    onClear: () => void;
}) {
    const [term, setTerm] = useState(line.medicine_id ? '' : line.medicine_name);
    const [needle, setNeedle] = useState(term.trim());
    const [open, setOpen] = useState(false);
    const [active, setActive] = useState(0);
    const [box, setBox] = useState({ top: 0, left: 0, width: 0 });

    const input = useRef<HTMLInputElement>(null);
    const panel = useRef<HTMLUListElement>(null);

    // A request per pause in typing, not per keystroke.
    useEffect(() => {
        const timer = setTimeout(() => setNeedle(term.trim()), 200);

        return () => clearTimeout(timer);
    }, [term]);

    const searching = open && term.trim().length >= 2;
    const search = useMedicineSearch(needle, searching);
    const results = search.data ?? [];
    const stock = useStoreAvailability(
        storeId,
        results.map((medicine) => medicine.id),
    );

    useLayoutEffect(() => {
        if (!open || !input.current) return;

        const rect = input.current.getBoundingClientRect();

        setBox({ top: rect.bottom + 4, left: rect.left, width: rect.width });
    }, [open, term]);

    useEffect(() => {
        if (!open) return;

        function away(event: MouseEvent) {
            const target = event.target as Node;

            if (!input.current?.contains(target) && !panel.current?.contains(target)) {
                setOpen(false);
            }
        }

        function shut(event: Event) {
            // Scrolling the list itself is not a reason to close it.
            if (panel.current?.contains(event.target as Node)) return;

            setOpen(false);
        }

        document.addEventListener('mousedown', away);
        window.addEventListener('scroll', shut, true);
        window.addEventListener('resize', shut);

        return () => {
            document.removeEventListener('mousedown', away);
            window.removeEventListener('scroll', shut, true);
            window.removeEventListener('resize', shut);
        };
    }, [open]);

    function choose(index: number) {
        const medicine = results[index];

        if (medicine) {
            onPick(medicine);
            setTerm('');
        }

        // The last option keeps the typed words, which onType already holds.
        setOpen(false);
    }

    if (line.medicine_id) {
        return (
            <div className="rx-medicine-name-row">
                <div style={{ display: 'flex', flexDirection: 'column', gap: '1px' }}>
                    <span className="rx-medicine-name" title={line.medicine_name}>
                        {line.medicine_name}
                    </span>
                    {line.category && <span className="rx-medicine-category">{line.category}</span>}
                </div>
                <button
                    type="button"
                    className="rx-change-med-btn"
                    aria-label="Choose a different medicine"
                    title="Choose a different medicine"
                    onClick={() => {
                        setTerm('');
                        onClear();
                    }}
                >
                    <i className="ti ti-x" aria-hidden="true" />
                </button>
            </div>
        );
    }

    return (
        <div className="rx-pick">
            <input
                ref={input}
                type="text"
                className={`form-control${invalid ? ' is-invalid' : ''}`}
                placeholder="Search the catalogue, or type a medicine that is not in it"
                aria-label="Medicine"
                value={term}
                onFocus={() => setOpen(true)}
                onChange={(event) => {
                    setTerm(event.target.value);
                    onType(event.target.value);
                    setActive(0);
                    setOpen(true);
                }}
                onKeyDown={(event) => {
                    if (event.key === 'Escape') {
                        setOpen(false);

                        return;
                    }

                    if (!searching) return;

                    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                        event.preventDefault();

                        const step = event.key === 'ArrowDown' ? 1 : -1;

                        setActive((was) => Math.max(0, Math.min(results.length, was + step)));
                    }

                    if (event.key === 'Enter') {
                        event.preventDefault();
                        choose(active);
                    }
                }}
            />

            {searching &&
                createPortal(
                    <ul
                        ref={panel}
                        className="rx-pop"
                        role="listbox"
                        style={{ top: box.top, left: box.left, width: Math.max(box.width, 300) }}
                    >
                        {results.length === 0 && (
                            <li className="rx-none">
                                {search.isFetching
                                    ? 'Searching the catalogue…'
                                    : `Nothing in the catalogue matches “${term.trim()}”.`}
                            </li>
                        )}

                        {results.map((medicine, index) => (
                            <li key={medicine.id}>
                                <button
                                    type="button"
                                    role="option"
                                    aria-selected={index === active}
                                    className={index === active ? 'is-active' : undefined}
                                    onMouseEnter={() => setActive(index)}
                                    onClick={() => choose(index)}
                                >
                                    <span>
                                        {medicine.display_name}
                                        {medicine.manufacturer && <small>{medicine.manufacturer}</small>}
                                    </span>

                                    <StockChip
                                        stock={stock.data?.find((row) => row.medicine_id === medicine.id)}
                                    />
                                </button>
                            </li>
                        ))}

                        <li>
                            <button
                                type="button"
                                role="option"
                                aria-selected={active === results.length}
                                className={active === results.length ? 'is-active' : undefined}
                                onMouseEnter={() => setActive(results.length)}
                                onClick={() => choose(results.length)}
                            >
                                <span>
                                    Keep “{term.trim()}” as written
                                    <small>Not in the catalogue: it can be printed, not dispensed</small>
                                </span>
                            </button>
                        </li>
                    </ul>,
                    document.body,
                )}
        </div>
    );
}

/** One line: the medicine, and how to take it. */
function LineEditor({
    line,
    index,
    storeId,
    stock,
    errors,
    onChange,
    onRemove,
}: {
    line: LineDraft;
    index: number;
    storeId: number | null;
    stock?: Availability;
    errors: Record<string, string[]>;
    onChange: (line: LineDraft) => void;
    onRemove: () => void;
}) {
    const error = (field: string) => errors[`items.${index}.${field}`]?.[0];
    const set = (patch: Partial<LineDraft>) => onChange({ ...line, ...patch });
    const number = (value: string) => (value === '' ? null : Number(value));

    const [collapsed, setCollapsed] = useState(false);
    const [advancedOpen, setAdvancedOpen] = useState(
        line.frequency === 'custom' || Boolean((line.morning ?? 0) || (line.afternoon ?? 0) || (line.evening ?? 0) || (line.night ?? 0))
    );

    const isDosed = line.item_kind === null || line.item_kind === 'medicine';

    const suggestion = line.medicine_id ? suggestQuantity(line) : null;
    const autoQuantity = line.prescribed_quantity === null && suggestion !== null;
    const formula = autoQuantity ? quantityFormula(line) : null;

    const effectiveQty = line.prescribed_quantity ?? suggestion;
    const qtyUnit = (() => {
        const unit = (line.dose_unit.trim() || line.base_unit || '').replace(/s$/, '');
        if (!unit) return '';
        return effectiveQty === 1 ? unit : `${unit}s`;
    })();
    const which = error('medicine_id') ?? error('medicine_name');
    const how = LINE_FIELDS.map(error).find(Boolean);

    const currentUnit = line.dose_unit.trim() || line.base_unit || '';
    const unitOptions = currentUnit && !UNIT_OPTIONS.includes(currentUnit)
        ? [currentUnit, ...UNIT_OPTIONS]
        : UNIT_OPTIONS;

    const effectiveCollapsed = collapsed && !which && !how;

    function handleFrequencyChange(freq: Frequency | '') {
        let slotsPatch: Partial<LineDraft> = {};

        if (freq === 'od') {
            slotsPatch = { morning: 1, afternoon: 0, evening: 0, night: 0 };
        } else if (freq === 'bd') {
            slotsPatch = { morning: 1, afternoon: 0, evening: 0, night: 1 };
        } else if (freq === 'tds') {
            slotsPatch = { morning: 1, afternoon: 1, evening: 0, night: 1 };
        } else if (freq === 'qid') {
            slotsPatch = { morning: 1, afternoon: 1, evening: 1, night: 1 };
        } else if (freq === 'hs') {
            slotsPatch = { morning: 0, afternoon: 0, evening: 0, night: 1 };
        } else if (freq === 'custom') {
            setAdvancedOpen(true);
            if (!line.morning && !line.afternoon && !line.evening && !line.night) {
                slotsPatch = { morning: 1, afternoon: 0, evening: 0, night: 1 };
            }
        }

        set({ frequency: freq, ...slotsPatch });
    }

    function handleSlotChange(slot: 'morning' | 'afternoon' | 'evening' | 'night', value: number) {
        const m = slot === 'morning' ? value : (line.morning ?? 0);
        const a = slot === 'afternoon' ? value : (line.afternoon ?? 0);
        const e = slot === 'evening' ? value : (line.evening ?? 0);
        const n = slot === 'night' ? value : (line.night ?? 0);

        let matchedFreq: Frequency = 'custom';
        if (m === 1 && a === 0 && e === 0 && n === 0) matchedFreq = 'od';
        else if (m === 1 && a === 0 && e === 0 && n === 1) matchedFreq = 'bd';
        else if (m === 1 && a === 1 && e === 0 && n === 1) matchedFreq = 'tds';
        else if (m === 1 && a === 1 && e === 1 && n === 1) matchedFreq = 'qid';
        else if (m === 0 && a === 0 && e === 0 && n === 1) matchedFreq = 'hs';

        set({ [slot]: value, frequency: matchedFreq });
    }

    return (
        <li className="rx-card">
            {/* Card Header */}
            <div className="rx-card-header">
                <div className="rx-card-title-group">
                    <div className="rx-pill-badge-wrapper">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none">
                            <path d="M10.5 13.5L13.5 10.5" stroke="#fff" strokeWidth="2" strokeLinecap="round"/>
                            <rect x="3" y="3" width="18" height="18" rx="9" transform="rotate(-45 12 12)" fill="url(#pill_grad)"/>
                            <defs>
                                <linearGradient id="pill_grad" x1="3" y1="3" x2="21" y2="21" gradientUnits="userSpaceOnUse">
                                    <stop stopColor="#ef4444"/>
                                    <stop offset="0.5" stopColor="#ef4444"/>
                                    <stop offset="0.5" stopColor="#3b82f6"/>
                                    <stop offset="1" stopColor="#2563eb"/>
                                </linearGradient>
                            </defs>
                        </svg>
                    </div>

                    <div className="rx-medicine-info">
                        <MedicinePicker
                            line={line}
                            storeId={storeId}
                            invalid={Boolean(which)}
                            onPick={(medicine) =>
                                set({
                                    medicine_id: medicine.id,
                                    medicine_name: medicine.display_name,
                                    category: medicine.category,
                                    item_kind: medicine.item_kind,
                                    base_unit: medicine.base_unit,
                                    dose_unit: line.dose_unit || medicine.base_unit || 'tablet',
                                    dose_amount: line.dose_amount ?? 1,
                                })
                            }
                            onType={(name) =>
                                set({ medicine_id: null, medicine_name: name, category: null, item_kind: null, base_unit: null })
                            }
                            onClear={() =>
                                set({ medicine_id: null, medicine_name: '', category: null, item_kind: null, base_unit: null })
                            }
                        />
                    </div>
                </div>

                <div className="rx-card-header-actions">
                    {line.medicine_id ? (
                        <StockChip stock={stock} />
                    ) : line.medicine_name.trim() ? (
                        <span className="rx-stock is-unlisted">Not in catalogue</span>
                    ) : null}

                    <button
                        type="button"
                        className="rx-icon-btn rx-btn-delete"
                        aria-label="Remove this medicine"
                        title="Remove this medicine"
                        onClick={onRemove}
                    >
                        <i className="ti ti-trash" aria-hidden="true" />
                    </button>

                    <button
                        type="button"
                        className="rx-icon-btn rx-btn-collapse"
                        aria-label={effectiveCollapsed ? 'Show this line' : 'Collapse this line'}
                        aria-expanded={!effectiveCollapsed}
                        onClick={() => setCollapsed((was) => !was)}
                    >
                        <i
                            className={effectiveCollapsed ? 'ti ti-chevron-down' : 'ti ti-chevron-up'}
                            aria-hidden="true"
                        />
                    </button>
                </div>
            </div>

            {which && <p className="rx-err">{which}</p>}

            {!effectiveCollapsed && (
                <div className="rx-card-body">
                    {isDosed && (
                        <div className="rx-fields-row rx-primary-fields">
                            {/* Dose */}
                            <div className="rx-field">
                                <label className="rx-field-label">Dose</label>
                                <div className="rx-dose-container">
                                    <input
                                        type="number"
                                        min={0}
                                        step="any"
                                        className="rx-input-box rx-dose-num"
                                        placeholder="1"
                                        aria-label="How much"
                                        value={line.dose_amount ?? ''}
                                        onChange={(event) => set({ dose_amount: number(event.target.value) })}
                                    />
                                    <div className="rx-select-box rx-dose-unit-box">
                                        <select
                                            className="rx-native-select"
                                            aria-label="Dose unit"
                                            value={line.dose_unit || line.base_unit || 'tablet'}
                                            onChange={(event) => set({ dose_unit: event.target.value })}
                                        >
                                            {unitOptions.map((unit) => (
                                                <option key={unit} value={unit}>
                                                    {unit}
                                                </option>
                                            ))}
                                        </select>
                                        <i className="ti ti-chevron-down rx-select-chevron" />
                                    </div>
                                </div>
                            </div>

                            {/* Frequency */}
                            <div className="rx-field">
                                <label className="rx-field-label">Frequency</label>
                                <div className="rx-input-box rx-with-icon">
                                    <div className="rx-icon-badge-blue">
                                        <i className="ti ti-target" aria-hidden="true" />
                                    </div>
                                    <div className="rx-select-flex">
                                        <select
                                            className="rx-native-select"
                                            value={line.frequency}
                                            onChange={(event) =>
                                                handleFrequencyChange(event.target.value as Frequency | '')
                                            }
                                        >
                                            <option value="">Choose frequency…</option>
                                            {(Object.keys(FREQUENCY_LABELS) as Frequency[]).map((key) => (
                                                <option key={key} value={key}>
                                                    {FREQUENCY_LABELS[key]}
                                                </option>
                                            ))}
                                        </select>
                                        <i className="ti ti-chevron-down rx-select-chevron" />
                                    </div>
                                </div>
                                {line.frequency && FREQUENCY_HINTS[line.frequency] && (
                                    <div className="rx-field-subtext">{FREQUENCY_HINTS[line.frequency]}</div>
                                )}
                            </div>

                            {/* Food */}
                            <div className="rx-field">
                                <label className="rx-field-label">Food</label>
                                <div className="rx-input-box rx-with-icon">
                                    <i className="ti ti-tools-kitchen-2 rx-blue-icon" aria-hidden="true" />
                                    <div className="rx-select-flex">
                                        <select
                                            className="rx-native-select"
                                            value={line.food_timing}
                                            onChange={(event) =>
                                                set({ food_timing: event.target.value as FoodTiming | '' })
                                            }
                                        >
                                            <option value="">Not specified</option>
                                            {(Object.keys(FOOD_LABELS) as FoodTiming[]).map((key) => (
                                                <option key={key} value={key}>
                                                    {FOOD_LABELS[key]}
                                                </option>
                                            ))}
                                        </select>
                                        <i className="ti ti-chevron-down rx-select-chevron" />
                                    </div>
                                </div>
                            </div>

                            {/* Duration */}
                            <div className="rx-field">
                                <label className="rx-field-label">Duration</label>
                                <div className="rx-duration-container">
                                    <div className="rx-input-box rx-duration-num-box">
                                        <i className="ti ti-calendar rx-blue-icon" aria-hidden="true" />
                                        <input
                                            type="number"
                                            min={1}
                                            className="rx-bare-input rx-duration-num"
                                            aria-label="How long"
                                            placeholder="10"
                                            disabled={line.duration_unit === 'continuous'}
                                            value={line.duration_unit === 'continuous' ? '' : (line.duration ?? '')}
                                            onChange={(event) => set({ duration: number(event.target.value) })}
                                        />
                                    </div>
                                    <div className="rx-select-box rx-duration-unit-box">
                                        <select
                                            className="rx-native-select"
                                            aria-label="Days, weeks or months"
                                            value={line.duration_unit}
                                            onChange={(event) =>
                                                set({ duration_unit: event.target.value as DurationUnit })
                                            }
                                        >
                                            {(Object.keys(DURATION_LABELS) as DurationUnit[]).map((key) => (
                                                <option key={key} value={key}>
                                                    {DURATION_LABELS[key]}
                                                </option>
                                            ))}
                                        </select>
                                        <i className="ti ti-chevron-down rx-select-chevron" />
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

                    <div className="rx-fields-row rx-secondary-fields">
                        {/* Quantity */}
                        <div className="rx-field">
                            <label className="rx-field-label">Quantity</label>
                            <div className="rx-qty-wrapper">
                                <div className="rx-qty-box">
                                    <input
                                        type="number"
                                        min={1}
                                        className="rx-qty-val-input"
                                        placeholder={suggestion !== null ? String(suggestion) : line.medicine_id ? '?' : '—'}
                                        value={line.prescribed_quantity ?? ''}
                                        onChange={(event) => set({ prescribed_quantity: number(event.target.value) })}
                                    />
                                    {effectiveQty !== null && qtyUnit && (
                                        <span className="rx-qty-unit-text">{qtyUnit}</span>
                                    )}
                                </div>

                                {autoQuantity && (
                                    <div className="rx-auto-calc-badge">
                                        <div className="rx-auto-calc-title">
                                            <i className="ti ti-circle-check-filled" aria-hidden="true" />
                                            <span>Auto calculated</span>
                                        </div>
                                        {formula && <span className="rx-auto-calc-formula">{formula}</span>}
                                    </div>
                                )}
                            </div>
                        </div>

                        {/* Instructions */}
                        <div className="rx-field">
                            <label className="rx-field-label">Instructions (optional)</label>
                            <div className="rx-input-box rx-with-icon">
                                <i className="ti ti-file-text rx-gray-icon" aria-hidden="true" />
                                <input
                                    type="text"
                                    className="rx-bare-input rx-full-input"
                                    placeholder="e.g. Take after meals, avoid driving etc."
                                    value={line.instructions}
                                    onChange={(event) => set({ instructions: event.target.value })}
                                />
                            </div>
                        </div>
                    </div>

                    {isDosed && (
                        <div className="rx-advanced-section">
                            <button
                                type="button"
                                className={`rx-advanced-bar ${advancedOpen ? 'is-open' : ''}`}
                                onClick={() => setAdvancedOpen(!advancedOpen)}
                            >
                                <div className="rx-advanced-bar-title">
                                    <i className="ti ti-settings" aria-hidden="true" />
                                    <span>Advanced dosing (Customize timing)</span>
                                </div>
                                <i className={advancedOpen ? 'ti ti-chevron-up' : 'ti ti-chevron-down'} aria-hidden="true" />
                            </button>

                            {advancedOpen && (
                                <div className="rx-advanced-panel">
                                    <div className="rx-timing-grid">
                                        {/* Morning */}
                                        <div className="rx-timing-card">
                                            <div className="rx-timing-header">
                                                <i className="ti ti-sun rx-icon-morning" aria-hidden="true" />
                                                <span>Morning</span>
                                            </div>
                                            <select
                                                className="form-select rx-timing-select"
                                                value={line.morning ?? 0}
                                                onChange={(e) => handleSlotChange('morning', Number(e.target.value))}
                                            >
                                                {[0, 0.5, 1, 1.5, 2, 2.5, 3].map((val) => (
                                                    <option key={val} value={val}>{val}</option>
                                                ))}
                                            </select>
                                        </div>

                                        {/* Afternoon */}
                                        <div className="rx-timing-card">
                                            <div className="rx-timing-header">
                                                <i className="ti ti-sun-high rx-icon-afternoon" aria-hidden="true" />
                                                <span>Afternoon</span>
                                            </div>
                                            <select
                                                className="form-select rx-timing-select"
                                                value={line.afternoon ?? 0}
                                                onChange={(e) => handleSlotChange('afternoon', Number(e.target.value))}
                                            >
                                                {[0, 0.5, 1, 1.5, 2, 2.5, 3].map((val) => (
                                                    <option key={val} value={val}>{val}</option>
                                                ))}
                                            </select>
                                        </div>

                                        {/* Evening */}
                                        <div className="rx-timing-card">
                                            <div className="rx-timing-header">
                                                <i className="ti ti-sunset rx-icon-evening" aria-hidden="true" />
                                                <span>Evening</span>
                                            </div>
                                            <select
                                                className="form-select rx-timing-select"
                                                value={line.evening ?? 0}
                                                onChange={(e) => handleSlotChange('evening', Number(e.target.value))}
                                            >
                                                {[0, 0.5, 1, 1.5, 2, 2.5, 3].map((val) => (
                                                    <option key={val} value={val}>{val}</option>
                                                ))}
                                            </select>
                                        </div>

                                        {/* Night */}
                                        <div className="rx-timing-card">
                                            <div className="rx-timing-header">
                                                <i className="ti ti-moon-stars rx-icon-night" aria-hidden="true" />
                                                <span>Night</span>
                                            </div>
                                            <select
                                                className="form-select rx-timing-select"
                                                value={line.night ?? 0}
                                                onChange={(e) => handleSlotChange('night', Number(e.target.value))}
                                            >
                                                {[0, 0.5, 1, 1.5, 2, 2.5, 3].map((val) => (
                                                    <option key={val} value={val}>{val}</option>
                                                ))}
                                            </select>
                                        </div>

                                        {/* Info Callout */}
                                        <div className="rx-timing-info-box">
                                            <i className="ti ti-info-circle rx-info-icon" aria-hidden="true" />
                                            <div className="rx-info-content">
                                                <strong>This will override the automatic frequency.</strong>
                                                <p>Use this if you want different doses at different times of the day.</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            )}
                        </div>
                    )}
                </div>
            )}

            {how && <p className="rx-err">{how}</p>}
        </li>
    );
}

/** An issued prescription: read, not edited. */
function IssuedLines({ prescription }: { prescription: Prescription }) {
    return (
        <>
            <ul className="rx-read">
                {(prescription.items ?? []).map((item) => (
                    <li key={item.id}>
                        <span>
                            <b>{item.line.drug}</b>
                            <small>
                                {[item.line.dose, item.line.frequency, item.line.duration, item.line.notes]
                                    .filter(Boolean)
                                    .join(' · ') || '—'}
                            </small>
                        </span>

                        <span className="rx-qty">
                            {item.prescribed_quantity !== null
                                ? `${item.prescribed_quantity} ${item.base_unit ?? ''}`.trim()
                                : ''}
                        </span>
                    </li>
                ))}
            </ul>

            <p className="rx-note">
                <i className="ti ti-lock" aria-hidden="true" />
                {STATUS_LABELS[prescription.status]}
                {prescription.valid_until ? `, valid until ${shortDate(prescription.valid_until)}` : ''}. To
                change it, it has to be cancelled and written again.
            </p>
        </>
    );
}

/**
 * The visit's prescription, inside the consultation.
 *
 * Structured lines against the medicine catalogue, with what the branch's
 * store holds beside each. Held as local state and saved on demand, like
 * the rest of the write-up; issued with its own button or when the
 * consultation is completed.
 *
 * Where prescribing is not open to this person here — the module is off at
 * the branch, or the role does not write prescriptions — the lines already
 * written are shown, read-only.
 */
export function PrescriptionEditor({
    appointmentId,
    fallback,
}: {
    appointmentId: number;
    /** What the consultation already shows, for when prescribing is not open here. */
    fallback: { drug: string }[];
}) {
    const { modules, capabilities } = useTenantAuth();

    const allowed =
        modules.includes('prescriptions') &&
        capabilities.includes('prescriptions.view') &&
        capabilities.includes('prescriptions.write');

    const visit = useVisitPrescription(appointmentId, allowed);
    const create = useCreatePrescription();
    const update = useUpdatePrescription();
    const issue = useIssuePrescription();

    const prescription = visit.data?.prescription ?? null;
    const store = visit.data?.store ?? null;

    const [open, setOpen] = useState(false);
    const [lines, setLines] = useState<LineDraft[]>([]);
    const [saved, setSaved] = useState('[]');
    const [loadedFor, setLoadedFor] = useState<number | null | undefined>(undefined);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [problem, setProblem] = useState<string | null>(null);

    /*
     * Taken from the server once per prescription.
     *
     * Every mutation anywhere refetches this query; re-reading the lines on
     * each refetch would throw away whatever the doctor is halfway through
     * typing.
     */
    useEffect(() => {
        if (!visit.data) return;

        const id = visit.data.prescription?.id ?? null;

        if (loadedFor === id) return;

        const next = (visit.data.prescription?.items ?? []).map(toDraft);

        setLines(next);
        setSaved(JSON.stringify(next.map(toPayload)));
        setLoadedFor(id);
    }, [visit.data, loadedFor]);

    const chosen = lines.map((line) => line.medicine_id).filter((id): id is number => id !== null);
    const stock = useStoreAvailability(store?.id ?? null, chosen);

    const editable = !prescription || prescription.is_draft;
    const dirty = JSON.stringify(lines.map(toPayload)) !== saved;
    const written = lines.filter((line) => line.medicine_id !== null || line.medicine_name.trim() !== '');
    const busy = create.isPending || update.isPending || issue.isPending;

    function fail(error: unknown) {
        const found = getValidationErrors(error);

        if (found) setErrors(found);
        else setProblem(resolveErrorMessage(error));

        setOpen(true);
    }

    /** Save the lines as they stand; blank rows are dropped rather than refused. */
    async function persist(): Promise<Prescription | null> {
        setErrors({});
        setProblem(null);

        if (written.length !== lines.length) setLines(written);

        const items = written.map(toPayload);

        try {
            const result = prescription
                ? await update.mutateAsync({ id: prescription.id, payload: { items } })
                : await create.mutateAsync({ appointment_id: appointmentId, items });

            // Keys kept by position, so saving never remounts the row being typed in.
            const next = (result.items ?? []).map((item, index) => ({
                ...toDraft(item),
                key: written[index]?.key ?? `rx-${item.id}`,
            }));

            setLines(next);
            setSaved(JSON.stringify(next.map(toPayload)));
            setLoadedFor(result.id);

            return result;
        } catch (error) {
            fail(error);

            return null;
        }
    }

    async function saveDraft() {
        const result = await persist();

        if (result) notify.success(`${result.prescription_number} saved as a draft`);
    }

    async function issueNow(): Promise<boolean> {
        const result = dirty || !prescription ? await persist() : prescription;

        if (!result) return false;

        try {
            await issue.mutateAsync(result.id);

            return true;
        } catch (error) {
            fail(error);

            return false;
        }
    }

    /*
     * Completing the consultation issues what has been written, so a visit
     * never ends with its prescription left unsigned. Nothing written means
     * nothing to issue.
     */
    useEffect(() =>
        registerFlush(appointmentId, async () => {
            if (!allowed || !editable || written.length === 0) return true;

            return issueNow();
        }),
    );

    if (!allowed) {
        return (
            <div className="cn-row">
                <i className="cn-icon is-green ti ti-pill" aria-hidden="true" />
                <span className="cn-label">Prescription</span>

                <div className="cn-value cn-summary">
                    {fallback.length === 0
                        ? 'Prescribing is not open to you at this branch'
                        : fallback.map((line) => line.drug).join(', ')}
                </div>

                <span />
            </div>
        );
    }

    const count = prescription?.items?.length ?? 0;

    return (
        <>
            {/*
                Summarised until somebody is changing it: "RX-00012 · 2
                medicines" is what a doctor needs while reading.
            */}
            <div className="cn-row">
                <i className="cn-icon is-green ti ti-pill" aria-hidden="true" />
                <span className="cn-label">Prescription</span>

                <div className="cn-value cn-summary">
                    {visit.isLoading ? (
                        'Loading…'
                    ) : prescription ? (
                        <>
                            <b className={`rx-status is-${prescription.status}`}>
                                {STATUS_LABELS[prescription.status]}
                            </b>
                            {prescription.prescription_number} · {count}{' '}
                            {count === 1 ? 'medicine' : 'medicines'}
                        </>
                    ) : (
                        'Nothing prescribed yet'
                    )}
                </div>

                <button type="button" className="cn-plus" onClick={() => setOpen(!open)}>
                    <i
                        className={open ? 'ti ti-x' : editable ? 'ti ti-plus' : 'ti ti-eye'}
                        aria-hidden="true"
                    />
                    {open ? 'Close' : !editable ? 'View' : prescription ? 'Edit prescription' : 'Add prescription'}
                </button>
            </div>

            {open && (
                <div className="cn-open">
                    <p className="rx-note">
                        <i className="ti ti-building-store" aria-hidden="true" />
                        {store
                            ? `Stock shown for ${store.name}. It is a guide — anything can still be prescribed.`
                            : 'No pharmacy stock is kept at this branch, so none is shown.'}
                    </p>

                    {!editable && prescription ? (
                        <IssuedLines prescription={prescription} />
                    ) : (
                        <>
                            {lines.length === 0 ? (
                                <p className="cn-quiet">Nothing prescribed yet. Add the first medicine below.</p>
                            ) : (
                                <ul className="rx-lines">
                                    {lines.map((line, index) => (
                                        <LineEditor
                                            key={line.key}
                                            line={line}
                                            index={index}
                                            storeId={store?.id ?? null}
                                            stock={stock.data?.find((row) => row.medicine_id === line.medicine_id)}
                                            errors={errors}
                                            onChange={(next) =>
                                                setLines(lines.map((other, at) => (at === index ? next : other)))
                                            }
                                            onRemove={() => setLines(lines.filter((_, at) => at !== index))}
                                        />
                                    ))}
                                </ul>
                            )}

                            <button
                                type="button"
                                className="cn-more"
                                onClick={() => setLines([...lines, blankLine()])}
                            >
                                <i className="ti ti-plus" aria-hidden="true" />
                                Add a medicine
                            </button>

                            {(problem || errors.items) && <p className="rx-err">{problem ?? errors.items?.[0]}</p>}

                            <div className="rx-foot">
                                <p>Kept as a draft until it is issued. Completing the consultation issues it.</p>

                                <div className="rx-acts">
                                    <button
                                        type="button"
                                        className="rx-btn is-quiet"
                                        disabled={busy || !dirty}
                                        onClick={() => void saveDraft()}
                                    >
                                        <i className="ti ti-device-floppy" aria-hidden="true" />
                                        Save draft
                                    </button>

                                    <button
                                        type="button"
                                        className="rx-btn is-main"
                                        disabled={busy || written.length === 0}
                                        onClick={() => void issueNow()}
                                    >
                                        <i className="ti ti-signature" aria-hidden="true" />
                                        Issue
                                    </button>
                                </div>
                            </div>
                        </>
                    )}
                </div>
            )}
        </>
    );
}
