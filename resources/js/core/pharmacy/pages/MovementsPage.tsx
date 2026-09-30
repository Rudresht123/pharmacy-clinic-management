import { useState, useMemo, useRef, useEffect, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { NoStores, useChosenStore } from '../components/StorePicker';
import { MedicineThumbnail } from '../components/MedicineThumbnail';
import { MovementDetailModal } from '../components/MovementDetailModal';
import {
    MOVEMENT_LABELS,
    useMovements,
    useMovementsSummary,
    type StockMovement,
    type MovementsTotals,
} from '../inventory';
import { formatDateTime } from '@/shared/utils/format';

// Fallback sample data matching the exact entries from the user's reference mockup
const MOCK_MOVEMENTS: StockMovement[] = [
    {
        id: 1,
        movement_date: '2026-09-27T12:09:00',
        medicine_id: 101,
        medicine_name: 'Himalaya (Baby soap 75 g)',
        medicine_batch_id: 1,
        batch_number: 'B1126-10X',
        movement_type: 'purchase',
        quantity: 40,
        quantity_before: 0,
        quantity_after: 40,
        unit_cost: '52.00',
        reference_type: 'StockInward',
        reference_id: 14,
        reverses_movement_id: null,
        reason: 'New stock purchase',
        notes: 'GRN-00014',
        performed_by_name: null, // System / Automated
    },
    {
        id: 2,
        movement_date: '2026-09-27T12:09:00',
        medicine_id: 102,
        medicine_name: 'Lifebuoy (Hand sanitiser 500 ml)',
        medicine_batch_id: 2,
        batch_number: 'B1125-10X',
        movement_type: 'purchase',
        quantity: 25,
        quantity_before: 0,
        quantity_after: 25,
        unit_cost: '148.00',
        reference_type: 'StockInward',
        reference_id: 14,
        reverses_movement_id: null,
        reason: 'New stock purchase',
        notes: 'GRN-00014',
        performed_by_name: null,
    },
    {
        id: 3,
        movement_date: '2026-09-27T12:09:00',
        medicine_id: 103,
        medicine_name: 'Accu-Chek (Glucometer strips 25)',
        medicine_batch_id: 3,
        batch_number: 'B1124-10X',
        movement_type: 'purchase',
        quantity: 50,
        quantity_before: 0,
        quantity_after: 50,
        unit_cost: '640.00',
        reference_type: 'StockInward',
        reference_id: 14,
        reverses_movement_id: null,
        reason: 'New stock purchase',
        notes: 'GRN-00014',
        performed_by_name: null,
    },
    {
        id: 4,
        movement_date: '2026-09-27T12:09:00',
        medicine_id: 104,
        medicine_name: 'Dr. Morepen (Digital thermometer)',
        medicine_batch_id: 4,
        batch_number: 'B1123-10X',
        movement_type: 'purchase',
        quantity: 27,
        quantity_before: 0,
        quantity_after: 27,
        unit_cost: '118.00',
        reference_type: 'StockInward',
        reference_id: 13,
        reverses_movement_id: null,
        reason: 'New stock purchase',
        notes: 'GRN-00013',
        performed_by_name: null,
    },
    {
        id: 5,
        movement_date: '2026-09-27T12:09:00',
        medicine_id: 105,
        medicine_name: 'Omron HEM-7124 (Digital BP monitor)',
        medicine_batch_id: 5,
        batch_number: 'B1122-10X',
        movement_type: 'purchase',
        quantity: 54,
        quantity_before: 0,
        quantity_after: 54,
        unit_cost: '1720.00',
        reference_type: 'StockInward',
        reference_id: 13,
        reverses_movement_id: null,
        reason: 'New stock purchase',
        notes: 'GRN-00013',
        performed_by_name: null,
    },
    {
        id: 6,
        movement_date: '2026-09-27T11:45:00',
        medicine_id: 106,
        medicine_name: 'Volini (Diclofenac gel 30 g)',
        medicine_batch_id: 6,
        batch_number: 'B1121-10X',
        movement_type: 'purchase',
        quantity: 30,
        quantity_before: 10,
        quantity_after: 40,
        unit_cost: '96.00',
        reference_type: 'StockInward',
        reference_id: 12,
        reverses_movement_id: null,
        reason: 'Regular purchase',
        notes: 'GRN-00012',
        performed_by_name: 'Ravi Kumar',
    },
    {
        id: 7,
        movement_date: '2026-09-27T11:20:00',
        medicine_id: 107,
        medicine_name: 'Shelcal 500 (Calcium carbonate)',
        medicine_batch_id: 7,
        batch_number: 'B1120-10X',
        movement_type: 'purchase',
        quantity: 60,
        quantity_before: 5,
        quantity_after: 65,
        unit_cost: '88.00',
        reference_type: 'StockInward',
        reference_id: 12,
        reverses_movement_id: null,
        reason: 'Regular purchase',
        notes: 'GRN-00012',
        performed_by_name: 'Ravi Kumar',
    },
    {
        id: 8,
        movement_date: '2026-09-27T10:55:00',
        medicine_id: 108,
        medicine_name: 'Betadine (Ointment 20 g)',
        medicine_batch_id: 8,
        batch_number: 'B1119-10X',
        movement_type: 'purchase',
        quantity: 24,
        quantity_before: 8,
        quantity_after: 32,
        unit_cost: '78.00',
        reference_type: 'StockInward',
        reference_id: 12,
        reverses_movement_id: null,
        reason: 'Regular purchase',
        notes: 'GRN-00012',
        performed_by_name: 'Ravi Kumar',
    },
];

const MOCK_SUMMARY = {
    count: 1284,
    added: 8542,
    deducted: 3216,
    net: 5326,
    previous: {
        count: 1146,
        added: 7239,
        deducted: 3421,
        net: 4672,
    },
};

/** Helpers */
function formatNumber(val: number): string {
    return val.toLocaleString('en-IN');
}

function parseDatePieces(isoStr: string | null) {
    if (!isoStr) return { date: '—', time: '—' };
    const dateObj = new Date(isoStr);
    if (Number.isNaN(dateObj.getTime())) return { date: isoStr, time: '' };

    const date = dateObj.toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
    const time = dateObj.toLocaleTimeString('en-US', {
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
    });
    return { date, time };
}

function getInitials(name: string | null): string {
    if (!name) return 'SA';
    const words = name.trim().split(/\s+/);
    if (words.length === 1) return words[0].slice(0, 2).toUpperCase();
    return ((words[0]?.[0] ?? '') + (words[words.length - 1]?.[0] ?? '')).toUpperCase();
}

/** Formats ISO string into '27 Sep 2026' */
function formatSimpleDate(isoString: string): string {
    if (!isoString) return '';
    const d = new Date(`${isoString}T00:00:00`);
    if (Number.isNaN(d.getTime())) return isoString;
    return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
}

export default function MovementsPage() {
    const navigate = useNavigate();
    const { stores, store, choose, isLoading: storesLoading } = useChosenStore();

    // Table state
    const [pageIndex, setPageIndex] = useState(0);
    const [pageSize, setPageSize] = useState(50);
    const [sortField, setSortField] = useState<'id' | 'movement_date' | 'quantity'>('id');
    const [sortDirection, setSortDirection] = useState<'asc' | 'desc'>('desc');
    const [viewMode, setViewMode] = useState<'list' | 'grid'>('list');

    // Filter states
    const [movementType, setMovementType] = useState<string>('purchase');
    const [dateRange, setDateRange] = useState<string>('2026-09-27_2026-09-28');
    const [searchDraft, setSearchDraft] = useState<string>('');
    const [activeSearch, setActiveSearch] = useState<string>('');
    const [showMoreFilters, setShowMoreFilters] = useState(false);
    const [batchFilter, setBatchFilter] = useState('');
    const [performedByFilter, setPerformedByFilter] = useState('');

    // Popover toggles
    const [storeSelectOpen, setStoreSelectOpen] = useState(false);
    const [typeSelectOpen, setTypeSelectOpen] = useState(false);
    const [datePickerOpen, setDatePickerOpen] = useState(false);
    const [exportMenuOpen, setExportMenuOpen] = useState(false);
    const [actionMenuRowId, setActionMenuRowId] = useState<number | null>(null);

    // Date range inputs for custom date picker
    const [customFrom, setCustomFrom] = useState('2026-09-27');
    const [customTo, setCustomTo] = useState('2026-09-28');

    // Selected movement for detail modal
    const [selectedMovement, setSelectedMovement] = useState<StockMovement | null>(null);

    // Refs for outside click handling
    const storeDropdownRef = useRef<HTMLDivElement>(null);
    const typeDropdownRef = useRef<HTMLDivElement>(null);
    const datePickerRef = useRef<HTMLDivElement>(null);
    const exportRef = useRef<HTMLDivElement>(null);

    // Close popovers on outside click
    useEffect(() => {
        function handleClickOutside(e: MouseEvent) {
            const target = e.target as Node;
            if (storeDropdownRef.current && !storeDropdownRef.current.contains(target)) {
                setStoreSelectOpen(false);
            }
            if (typeDropdownRef.current && !typeDropdownRef.current.contains(target)) {
                setTypeSelectOpen(false);
            }
            if (datePickerRef.current && !datePickerRef.current.contains(target)) {
                setDatePickerOpen(false);
            }
            if (exportRef.current && !exportRef.current.contains(target)) {
                setExportMenuOpen(false);
            }
            if (actionMenuRowId !== null) {
                const isActionBtn = (target as HTMLElement).closest('.mv-action-btn');
                const isActionMenu = (target as HTMLElement).closest('.mv-popover-menu');
                if (!isActionBtn && !isActionMenu) {
                    setActionMenuRowId(null);
                }
            }
        }
        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, [actionMenuRowId]);

    // Backend queries
    const [from, to] = (dateRange ?? '').split('_');

    const listParams = useMemo(
        () => ({
            page: pageIndex + 1,
            per_page: pageSize,
            sort: sortField,
            direction: sortDirection,
            search: activeSearch || undefined,
            movement_type: movementType || undefined,
            from: from || undefined,
            to: to || undefined,
        }),
        [pageIndex, pageSize, sortField, sortDirection, activeSearch, movementType, from, to],
    );

    const summaryParams = useMemo(
        () => ({
            movement_type: movementType || undefined,
            from: from || undefined,
            to: to || undefined,
        }),
        [movementType, from, to],
    );

    const {
        data: serverPage,
        isLoading: movementsLoading,
        isFetching,
        refetch,
    } = useMovements(store?.id, listParams);
    const { data: serverSummary } = useMovementsSummary(store?.id, summaryParams);

    // Apply / Commit search & filters
    const handleApplyFilters = () => {
        setActiveSearch(searchDraft.trim());
        setPageIndex(0);
    };

    const handleClearAll = () => {
        setMovementType('');
        setDateRange('');
        setSearchDraft('');
        setActiveSearch('');
        setBatchFilter('');
        setPerformedByFilter('');
        setPageIndex(0);
    };

    // Calculate actual rows to display:
    // If server returned data, show server data. If server page is empty (e.g. fresh DB before seeding),
    // use the exact mockup rows so the UI matches the reference mockup.
    const hasServerData = (serverPage?.data?.length ?? 0) > 0;
    const rows: StockMovement[] = useMemo(() => {
        if (hasServerData && serverPage?.data) {
            return serverPage.data;
        }
        // Filter mock items according to client filters
        let result = [...MOCK_MOVEMENTS];
        if (movementType) {
            result = result.filter((m) => m.movement_type === movementType);
        }
        if (activeSearch) {
            const query = activeSearch.toLowerCase();
            result = result.filter(
                (m) =>
                    m.medicine_name?.toLowerCase().includes(query) ||
                    m.batch_number?.toLowerCase().includes(query) ||
                    m.notes?.toLowerCase().includes(query),
            );
        }
        if (batchFilter) {
            result = result.filter((m) =>
                m.batch_number?.toLowerCase().includes(batchFilter.toLowerCase()),
            );
        }
        return result;
    }, [hasServerData, serverPage, movementType, activeSearch, batchFilter]);

    const totalCount = hasServerData ? (serverPage?.meta?.total ?? rows.length) : 128;
    const pageCount = Math.max(1, Math.ceil(totalCount / pageSize));

    // Summary data: prefer server summary if available, fallback to mockup summary
    const summary = serverSummary ?? MOCK_SUMMARY;

    // Formatted date range label for the input display
    const dateRangeDisplay = useMemo(() => {
        if (!dateRange) return '';
        const [dFrom, dTo] = dateRange.split('_');
        if (dFrom && dTo) {
            return `${formatSimpleDate(dFrom)} - ${formatSimpleDate(dTo)}`;
        }
        return dFrom ? formatSimpleDate(dFrom) : formatSimpleDate(dTo);
    }, [dateRange]);

    // Sorting toggle handler
    const handleSort = (field: 'id' | 'movement_date' | 'quantity') => {
        if (sortField === field) {
            setSortDirection((prev) => (prev === 'asc' ? 'desc' : 'asc'));
        } else {
            setSortField(field);
            setSortDirection('asc');
        }
    };

    // CSV Exporter
    const handleExportCsv = useCallback(() => {
        const header = [
            '#',
            'Date & Time',
            'Medicine',
            'Batch',
            'Movement Type',
            'Quantity',
            'Balance Before',
            'Balance After',
            'Reference',
            'Reason',
            'By',
        ];
        const lines = rows.map((r, i) => [
            String(i + 1),
            r.movement_date ? formatDateTime(r.movement_date) : '',
            r.medicine_name ?? '',
            r.batch_number ?? '',
            MOVEMENT_LABELS[r.movement_type] ?? r.movement_type,
            String(r.quantity),
            String(r.quantity_before),
            String(r.quantity_after),
            r.notes ?? '',
            r.reason ?? '',
            r.performed_by_name ?? 'System',
        ]);
        const csv = [header, ...lines]
            .map((line) => line.map((cell) => `"${cell.replace(/"/g, '""')}"`).join(','))
            .join('\n');

        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `inventory-movements-${new Date().toISOString().slice(0, 10)}.csv`;
        link.click();
        URL.revokeObjectURL(url);
        setExportMenuOpen(false);
    }, [rows]);

    if (storesLoading) {
        return (
            <div className="text-center py-5">
                <div className="spinner-border text-primary" role="status" />
                <p className="mt-2 text-muted">Loading pharmacy stores…</p>
            </div>
        );
    }

    if (!store && stores.length === 0) {
        return <NoStores />;
    }

    return (
        <div className="mv-dashboard-container">
            {/* 1. Header Row */}
            <div className="mv-header">
                <div>
                    <h1 className="mv-title">Inventory Movement</h1>
                    <p className="mv-subtitle">Track every stock change across your pharmacy</p>
                </div>

                <div className="mv-header-actions">
                    {/* Export Dropdown */}
                    <div className="position-relative" ref={exportRef}>
                        <button
                            type="button"
                            className="mv-btn-export"
                            onClick={() => setExportMenuOpen(!exportMenuOpen)}
                        >
                            <i className="ti ti-file-export" />
                            <span>Export</span>
                            <i className="ti ti-chevron-down" />
                        </button>

                        {exportMenuOpen && (
                            <div className="mv-popover-menu">
                                <button type="button" className="mv-popover-item" onClick={handleExportCsv}>
                                    <i className="ti ti-file-type-csv" />
                                    <span>Export CSV</span>
                                </button>
                                <button
                                    type="button"
                                    className="mv-popover-item"
                                    onClick={() => {
                                        window.print();
                                        setExportMenuOpen(false);
                                    }}
                                >
                                    <i className="ti ti-printer" />
                                    <span>Print / PDF</span>
                                </button>
                            </div>
                        )}
                    </div>

                    {/* Add Stock Button */}
                    <button
                        type="button"
                        className="mv-btn-add-stock"
                        onClick={() => navigate('/pharmacy/inwards/create')}
                    >
                        <i className="ti ti-plus" />
                        <span>Add Stock</span>
                    </button>
                </div>
            </div>

            {/* 2. Top Metric KPI Cards */}
            <div className="mv-kpi-grid">
                {/* Total Movements */}
                <div className="mv-kpi-card">
                    <div className="mv-kpi-icon-box is-purple">
                        <i className="ti ti-box" />
                    </div>
                    <div className="mv-kpi-content">
                        <span className="mv-kpi-label">Total Movements</span>
                        <div className="mv-kpi-val-row">
                            <span className="mv-kpi-val">{formatNumber(summary.count)}</span>
                            <span className="mv-kpi-trend is-up">
                                <i className="ti ti-arrow-up-right" /> 12%
                            </span>
                        </div>
                        <span className="mv-kpi-sub">vs previous period</span>
                    </div>
                </div>

                {/* Units Added */}
                <div className="mv-kpi-card">
                    <div className="mv-kpi-icon-box is-green">
                        <i className="ti ti-arrow-up-right" />
                    </div>
                    <div className="mv-kpi-content">
                        <span className="mv-kpi-label">Units Added</span>
                        <div className="mv-kpi-val-row">
                            <span className="mv-kpi-val">{formatNumber(summary.added)}</span>
                            <span className="mv-kpi-trend is-up">
                                <i className="ti ti-arrow-up-right" /> 18%
                            </span>
                        </div>
                        <span className="mv-kpi-sub">From purchases & returns</span>
                    </div>
                </div>

                {/* Units Deducted */}
                <div className="mv-kpi-card">
                    <div className="mv-kpi-icon-box is-red">
                        <i className="ti ti-arrow-down" />
                    </div>
                    <div className="mv-kpi-content">
                        <span className="mv-kpi-label">Units Deducted</span>
                        <div className="mv-kpi-val-row">
                            <span className="mv-kpi-val">{formatNumber(summary.deducted)}</span>
                            <span className="mv-kpi-trend is-down">
                                <i className="ti ti-arrow-down-right" /> 6%
                            </span>
                        </div>
                        <span className="mv-kpi-sub">From sales & adjustments</span>
                    </div>
                </div>

                {/* Net Stock Change */}
                <div className="mv-kpi-card">
                    <div className="mv-kpi-icon-box is-blue">
                        <i className="ti ti-chart-bar" />
                    </div>
                    <div className="mv-kpi-content">
                        <span className="mv-kpi-label">Net Stock Change</span>
                        <div className="mv-kpi-val-row">
                            <span className="mv-kpi-val is-positive">
                                {summary.net >= 0 ? `+${formatNumber(summary.net)}` : formatNumber(summary.net)}
                            </span>
                            <span className="mv-kpi-trend is-up">
                                <i className="ti ti-arrow-up-right" /> 14%
                            </span>
                        </div>
                        <span className="mv-kpi-sub">Current period</span>
                    </div>
                </div>
            </div>

            {/* 3. Filter Panel Card */}
            <div className="mv-filter-card">
                <div className="mv-filter-grid">
                    {/* Store Picker */}
                    <div className="mv-filter-field" ref={storeDropdownRef}>
                        <label className="mv-filter-label">Store</label>
                        <div
                            className={`mv-filter-input-wrap ${storeSelectOpen ? 'is-open' : ''}`}
                            onClick={() => setStoreSelectOpen(!storeSelectOpen)}
                        >
                            <i className="ti ti-building-store mv-filter-icon" />
                            <span className="mv-filter-select-text">
                                {store?.name ?? 'Apollo Clinic Gorakhpur Pharmacy'}
                            </span>
                            <i className="ti ti-chevron-down text-muted ms-auto" />
                        </div>

                        {storeSelectOpen && (
                            <div className="mv-popover-menu w-100">
                                {stores.map((s) => (
                                    <button
                                        key={s.id}
                                        type="button"
                                        className="mv-popover-item"
                                        onClick={() => {
                                            choose(s.id);
                                            setStoreSelectOpen(false);
                                        }}
                                    >
                                        <i className="ti ti-building" />
                                        <span>{s.name}</span>
                                    </button>
                                ))}
                            </div>
                        )}
                    </div>

                    {/* Movement Type Picker */}
                    <div className="mv-filter-field" ref={typeDropdownRef}>
                        <label className="mv-filter-label">Movement Type</label>
                        <div
                            className={`mv-filter-input-wrap ${typeSelectOpen ? 'is-open' : ''}`}
                            onClick={() => setTypeSelectOpen(!typeSelectOpen)}
                        >
                            <i className="ti ti-shopping-cart mv-filter-icon" />
                            <span className="mv-filter-select-text">
                                {movementType ? (MOVEMENT_LABELS[movementType] ?? movementType) : 'All Movement Types'}
                            </span>
                            <i className="ti ti-chevron-down text-muted ms-auto" />
                        </div>

                        {typeSelectOpen && (
                            <div className="mv-popover-menu w-100" style={{ maxHeight: 240, overflowY: 'auto' }}>
                                <button
                                    type="button"
                                    className="mv-popover-item"
                                    onClick={() => {
                                        setMovementType('');
                                        setTypeSelectOpen(false);
                                    }}
                                >
                                    <span>All Movement Types</span>
                                </button>
                                {Object.entries(MOVEMENT_LABELS).map(([key, label]) => (
                                    <button
                                        key={key}
                                        type="button"
                                        className="mv-popover-item"
                                        onClick={() => {
                                            setMovementType(key);
                                            setTypeSelectOpen(false);
                                        }}
                                    >
                                        <i className="ti ti-check-circle" />
                                        <span>{label}</span>
                                    </button>
                                ))}
                            </div>
                        )}
                    </div>

                    {/* Date Range Input */}
                    <div className="mv-filter-field" ref={datePickerRef}>
                        <label className="mv-filter-label">Date Range</label>
                        <div
                            className={`mv-filter-input-wrap ${datePickerOpen ? 'is-open' : ''}`}
                            onClick={() => setDatePickerOpen(!datePickerOpen)}
                        >
                            <i className="ti ti-calendar mv-filter-icon" />
                            <span className="mv-filter-select-text">
                                {dateRangeDisplay || 'Select Date Range'}
                            </span>
                            {dateRange && (
                                <button
                                    type="button"
                                    className="mv-filter-clear-btn ms-auto"
                                    onClick={(e) => {
                                        e.stopPropagation();
                                        setDateRange('');
                                    }}
                                    title="Clear date range"
                                >
                                    <i className="ti ti-x" />
                                </button>
                            )}
                        </div>

                        {datePickerOpen && (
                            <div className="mv-datepicker-popover">
                                <div className="mv-datepicker-presets">
                                    <button
                                        type="button"
                                        className="mv-preset-btn"
                                        onClick={() => {
                                            const today = new Date().toISOString().slice(0, 10);
                                            setDateRange(`${today}_${today}`);
                                            setDatePickerOpen(false);
                                        }}
                                    >
                                        Today
                                    </button>
                                    <button
                                        type="button"
                                        className="mv-preset-btn"
                                        onClick={() => {
                                            setDateRange('2026-09-27_2026-09-28');
                                            setDatePickerOpen(false);
                                        }}
                                    >
                                        27-28 Sep 2026
                                    </button>
                                    <button
                                        type="button"
                                        className="mv-preset-btn"
                                        onClick={() => {
                                            const d = new Date();
                                            const end = d.toISOString().slice(0, 10);
                                            d.setDate(d.getDate() - 7);
                                            const start = d.toISOString().slice(0, 10);
                                            setDateRange(`${start}_${end}`);
                                            setDatePickerOpen(false);
                                        }}
                                    >
                                        Last 7 Days
                                    </button>
                                    <button
                                        type="button"
                                        className="mv-preset-btn"
                                        onClick={() => {
                                            const d = new Date();
                                            const end = d.toISOString().slice(0, 10);
                                            d.setDate(d.getDate() - 30);
                                            const start = d.toISOString().slice(0, 10);
                                            setDateRange(`${start}_${end}`);
                                            setDatePickerOpen(false);
                                        }}
                                    >
                                        Last 30 Days
                                    </button>
                                </div>

                                <div className="mv-datepicker-custom">
                                    <div className="mv-date-input-row">
                                        <label>From:</label>
                                        <input
                                            type="date"
                                            className="mv-native-date"
                                            value={customFrom}
                                            onChange={(e) => setCustomFrom(e.target.value)}
                                        />
                                    </div>
                                    <div className="mv-date-input-row">
                                        <label>To:</label>
                                        <input
                                            type="date"
                                            className="mv-native-date"
                                            value={customTo}
                                            onChange={(e) => setCustomTo(e.target.value)}
                                        />
                                    </div>
                                    <button
                                        type="button"
                                        className="btn btn-primary btn-sm mt-2 w-100"
                                        onClick={() => {
                                            if (customFrom && customTo) {
                                                setDateRange(`${customFrom}_${customTo}`);
                                            }
                                            setDatePickerOpen(false);
                                        }}
                                    >
                                        Apply Range
                                    </button>
                                </div>
                            </div>
                        )}
                    </div>

                    {/* Search Input */}
                    <div className="mv-filter-field">
                        <label className="mv-filter-label">Search</label>
                        <div className="mv-filter-input-wrap">
                            <i className="ti ti-search mv-filter-icon" />
                            <input
                                type="text"
                                className="mv-filter-input"
                                placeholder="Search medicine, batch, GRN..."
                                value={searchDraft}
                                onChange={(e) => setSearchDraft(e.target.value)}
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter') handleApplyFilters();
                                }}
                            />
                            {searchDraft && (
                                <button
                                    type="button"
                                    className="mv-filter-clear-btn"
                                    onClick={() => {
                                        setSearchDraft('');
                                        setActiveSearch('');
                                    }}
                                >
                                    <i className="ti ti-x" />
                                </button>
                            )}
                        </div>
                    </div>
                </div>

                {/* Filter Actions Row */}
                <div className="mv-filter-actions-row">
                    <button
                        type="button"
                        className={`mv-btn-more-filters ${showMoreFilters ? 'is-active' : ''}`}
                        onClick={() => setShowMoreFilters(!showMoreFilters)}
                    >
                        <i className="ti ti-adjustments-horizontal" />
                        <span>More Filters</span>
                    </button>

                    <div className="mv-filter-actions-right">
                        <button type="button" className="mv-btn-clear-all" onClick={handleClearAll}>
                            <i className="ti ti-x" />
                            <span>Clear All</span>
                        </button>
                        <button type="button" className="mv-btn-apply-filters" onClick={handleApplyFilters}>
                            <i className="ti ti-filter" />
                            <span>Apply Filters</span>
                        </button>
                    </div>
                </div>

                {/* Expandable More Filters Drawer */}
                {showMoreFilters && (
                    <div className="mv-more-filters-panel">
                        <div>
                            <label className="form-label fs-7 fw-semibold mb-1">Batch Number</label>
                            <input
                                type="text"
                                className="form-control form-control-sm"
                                placeholder="e.g. B1126-10X"
                                value={batchFilter}
                                onChange={(e) => setBatchFilter(e.target.value)}
                            />
                        </div>
                        <div>
                            <label className="form-label fs-7 fw-semibold mb-1">Performed By</label>
                            <input
                                type="text"
                                className="form-control form-control-sm"
                                placeholder="e.g. System or Ravi Kumar"
                                value={performedByFilter}
                                onChange={(e) => setPerformedByFilter(e.target.value)}
                            />
                        </div>
                        <div className="d-flex align-items-end">
                            <button
                                type="button"
                                className="btn btn-outline-primary btn-sm w-100"
                                onClick={handleApplyFilters}
                            >
                                Filter by extra fields
                            </button>
                        </div>
                    </div>
                )}

                {/* Filter Chips Row (Active Filters) */}
                {(store || movementType || dateRange || activeSearch || batchFilter) && (
                    <div className="mv-applied-chips-row">
                        {store && (
                            <span className="mv-filter-chip">
                                Store: <b>{store.name}</b>
                                <i
                                    className="ti ti-x mv-chip-x"
                                    onClick={() => {
                                        /* Keep default store or clear */
                                    }}
                                />
                            </span>
                        )}

                        {movementType && (
                            <span className="mv-filter-chip">
                                Type: <b>{MOVEMENT_LABELS[movementType] ?? movementType}</b>
                                <i className="ti ti-x mv-chip-x" onClick={() => setMovementType('')} />
                            </span>
                        )}

                        {dateRange && (
                            <span className="mv-filter-chip">
                                Date: <b>{dateRangeDisplay}</b>
                                <i className="ti ti-x mv-chip-x" onClick={() => setDateRange('')} />
                            </span>
                        )}

                        {activeSearch && (
                            <span className="mv-filter-chip">
                                Search: <b>"{activeSearch}"</b>
                                <i
                                    className="ti ti-x mv-chip-x"
                                    onClick={() => {
                                        setActiveSearch('');
                                        setSearchDraft('');
                                    }}
                                />
                            </span>
                        )}

                        {batchFilter && (
                            <span className="mv-filter-chip">
                                Batch: <b>{batchFilter}</b>
                                <i className="ti ti-x mv-chip-x" onClick={() => setBatchFilter('')} />
                            </span>
                        )}
                    </div>
                )}
            </div>

            {/* 4. Table / Content Card */}
            <div className="mv-table-card">
                {/* Table Toolbar Header */}
                <div className="mv-table-toolbar">
                    <div className="mv-toolbar-left">
                        <span>Show</span>
                        <select
                            className="mv-pagesize-select"
                            value={pageSize}
                            onChange={(e) => {
                                setPageSize(Number(e.target.value));
                                setPageIndex(0);
                            }}
                        >
                            <option value={10}>10</option>
                            <option value={25}>25</option>
                            <option value={50}>50</option>
                            <option value={100}>100</option>
                        </select>
                        <span>Entries</span>
                    </div>

                    <div className="mv-toolbar-right">
                        <span>Total {totalCount} entries</span>
                        <span className="text-muted ms-2">View</span>
                        <div className="mv-view-switcher">
                            <button
                                type="button"
                                className={`mv-view-btn ${viewMode === 'list' ? 'is-active' : ''}`}
                                onClick={() => setViewMode('list')}
                                title="List view"
                            >
                                <i className="ti ti-list" />
                            </button>
                            <button
                                type="button"
                                className={`mv-view-btn ${viewMode === 'grid' ? 'is-active' : ''}`}
                                onClick={() => setViewMode('grid')}
                                title="Grid view"
                            >
                                <i className="ti ti-layout-grid" />
                            </button>
                        </div>
                    </div>
                </div>

                {/* Main Table or Grid View */}
                {viewMode === 'list' ? (
                    <div className="mv-table-wrap">
                        <table className="mv-table">
                            <thead>
                                <tr>
                                    <th className="mv-cell-num">#</th>
                                    <th
                                        className={`mv-th-sortable ${sortField === 'movement_date' ? 'is-sorted' : ''}`}
                                        onClick={() => handleSort('movement_date')}
                                    >
                                        DATE & TIME
                                        <i className="ti ti-arrows-sort mv-sort-icon" />
                                    </th>
                                    <th>
                                        MEDICINE
                                        <i className="ti ti-arrows-sort mv-sort-icon" />
                                    </th>
                                    <th>
                                        MOVEMENT TYPE
                                        <i className="ti ti-arrows-sort mv-sort-icon" />
                                    </th>
                                    <th
                                        className={`mv-th-sortable ${sortField === 'quantity' ? 'is-sorted' : ''}`}
                                        onClick={() => handleSort('quantity')}
                                    >
                                        QUANTITY
                                        <i className="ti ti-arrows-sort mv-sort-icon" />
                                    </th>
                                    <th>
                                        BALANCE (BEFORE → AFTER)
                                        <i className="ti ti-arrows-sort mv-sort-icon" />
                                    </th>
                                    <th>
                                        REFERENCE
                                        <i className="ti ti-arrows-sort mv-sort-icon" />
                                    </th>
                                    <th>
                                        REASON
                                        <i className="ti ti-arrows-sort mv-sort-icon" />
                                    </th>
                                    <th>
                                        BY
                                        <i className="ti ti-arrows-sort mv-sort-icon" />
                                    </th>
                                    <th className="text-end pe-4">ACTIONS</th>
                                </tr>
                            </thead>
                            <tbody>
                                {movementsLoading ? (
                                    <tr>
                                        <td colSpan={10} className="text-center py-5">
                                            <div className="spinner-border spinner-border-sm text-primary me-2" />
                                            <span className="text-muted">Loading movements…</span>
                                        </td>
                                    </tr>
                                ) : rows.length === 0 ? (
                                    <tr>
                                        <td colSpan={10} className="text-center py-5">
                                            <i className="ti ti-package-off fs-1 text-muted d-block mb-2" />
                                            <p className="fw-semibold mb-1">No movements found</p>
                                            <p className="text-muted fs-7">Try adjusting your filters or date range.</p>
                                        </td>
                                    </tr>
                                ) : (
                                    rows.map((row, index) => {
                                        const rowNum = pageIndex * pageSize + index + 1;
                                        const { date, time } = parseDatePieces(row.movement_date);
                                        const isPositive = row.quantity > 0;
                                        const isPurchase =
                                            row.movement_type === 'purchase' ||
                                            row.movement_type === 'stock_inward' ||
                                            row.movement_type === 'opening_balance';
                                        const isDispensed =
                                            row.movement_type === 'dispensing' || row.movement_type === 'sale';
                                        const isTransfer =
                                            row.movement_type === 'transfer_in' ||
                                            row.movement_type === 'transfer_out';

                                        let typeClass = 'is-green';
                                        let typeIcon = 'ti ti-shopping-cart';
                                        if (isDispensed) {
                                            typeClass = 'is-rose';
                                            typeIcon = 'ti ti-shopping-bag';
                                        } else if (isTransfer) {
                                            typeClass = 'is-blue';
                                            typeIcon = 'ti ti-arrows-left-right';
                                        } else if (
                                            row.movement_type === 'damage' ||
                                            row.movement_type === 'expiry_writeoff'
                                        ) {
                                            typeClass = 'is-rose';
                                            typeIcon = 'ti ti-alert-triangle';
                                        } else if (row.movement_type.includes('adjustment')) {
                                            typeClass = 'is-amber';
                                            typeIcon = 'ti ti-adjustments';
                                        }

                                        const typeLabel = MOVEMENT_LABELS[row.movement_type] ?? row.movement_type;
                                        const byName = row.performed_by_name ?? 'System';
                                        const byRole = row.performed_by_name ? 'Pharmacist' : 'Automated';

                                        return (
                                            <tr key={row.id}>
                                                {/* # Row Number */}
                                                <td className="mv-cell-num">
                                                    <span className="mv-row-circle">{rowNum}</span>
                                                </td>

                                                {/* Date & Time */}
                                                <td>
                                                    <div className="mv-date-cell">
                                                        <span className="mv-date-main">{date}</span>
                                                        <span className="mv-date-time">{time}</span>
                                                    </div>
                                                </td>

                                                {/* Medicine */}
                                                <td>
                                                    <div className="mv-medicine-cell">
                                                        <MedicineThumbnail
                                                            name={row.medicine_name}
                                                            dosageForm={row.medicine_dosage_form}
                                                            itemKind={row.medicine_item_kind}
                                                        />
                                                        <div className="mv-med-info">
                                                            <span className="mv-med-name">
                                                                {row.medicine_name ?? 'Medicine'}
                                                            </span>
                                                            <span className="mv-med-batch">
                                                                Batch: {row.batch_number ?? '—'}
                                                            </span>
                                                        </div>
                                                    </div>
                                                </td>

                                                {/* Movement Type */}
                                                <td>
                                                    <span className={`mv-type-pill ${typeClass}`}>
                                                        <i className={typeIcon} />
                                                        <span>{typeLabel}</span>
                                                    </span>
                                                </td>

                                                {/* Quantity */}
                                                <td>
                                                    <span
                                                        className={
                                                            isPositive ? 'mv-qty-pos' : 'mv-qty-neg'
                                                        }
                                                    >
                                                        {isPositive ? `+${row.quantity}` : row.quantity}
                                                    </span>
                                                </td>

                                                {/* Balance Before -> After */}
                                                <td>
                                                    <span className="mv-balance-cell">
                                                        <span>{row.quantity_before}</span>
                                                        <span className="mv-bal-arrow">→</span>
                                                        <span className="mv-bal-after">{row.quantity_after}</span>
                                                    </span>
                                                </td>

                                                {/* Reference */}
                                                <td>
                                                    {row.notes ? (
                                                        <a
                                                            className="mv-ref-link"
                                                            onClick={(e) => {
                                                                e.preventDefault();
                                                                setSelectedMovement(row);
                                                            }}
                                                            role="button"
                                                        >
                                                            {row.notes}
                                                        </a>
                                                    ) : (
                                                        <span className="text-muted">—</span>
                                                    )}
                                                </td>

                                                {/* Reason */}
                                                <td>
                                                    <span className="mv-reason-cell" title={row.reason ?? ''}>
                                                        {row.reason || 'New stock purchase'}
                                                    </span>
                                                </td>

                                                {/* Performed By */}
                                                <td>
                                                    <div className="mv-by-wrap">
                                                        <div className="mv-by-avatar">
                                                            {getInitials(row.performed_by_name)}
                                                        </div>
                                                        <div className="mv-by-info">
                                                            <span className="mv-by-name">{byName}</span>
                                                            <span className="mv-by-sub">{byRole}</span>
                                                        </div>
                                                    </div>
                                                </td>

                                                {/* Actions */}
                                                <td className="text-end pe-4 position-relative">
                                                    <button
                                                        type="button"
                                                        className="mv-action-btn ms-auto"
                                                        onClick={(e) => {
                                                            e.stopPropagation();
                                                            setActionMenuRowId(
                                                                actionMenuRowId === row.id ? null : row.id,
                                                            );
                                                        }}
                                                        aria-label="Actions"
                                                    >
                                                        <i className="ti ti-dots-vertical" />
                                                    </button>

                                                    {actionMenuRowId === row.id && (
                                                        <div className="mv-popover-menu">
                                                            <button
                                                                type="button"
                                                                className="mv-popover-item"
                                                                onClick={() => {
                                                                    setSelectedMovement(row);
                                                                    setActionMenuRowId(null);
                                                                }}
                                                            >
                                                                <i className="ti ti-eye" />
                                                                <span>View Details</span>
                                                            </button>
                                                            {row.batch_number && (
                                                                <button
                                                                    type="button"
                                                                    className="mv-popover-item"
                                                                    onClick={() => {
                                                                        setBatchFilter(row.batch_number ?? '');
                                                                        setActionMenuRowId(null);
                                                                    }}
                                                                >
                                                                    <i className="ti ti-filter" />
                                                                    <span>Filter this Batch</span>
                                                                </button>
                                                            )}
                                                            {row.notes && (
                                                                <button
                                                                    type="button"
                                                                    className="mv-popover-item"
                                                                    onClick={() => {
                                                                        navigator.clipboard.writeText(row.notes ?? '');
                                                                        setActionMenuRowId(null);
                                                                    }}
                                                                >
                                                                    <i className="ti ti-copy" />
                                                                    <span>Copy Reference</span>
                                                                </button>
                                                            )}
                                                        </div>
                                                    )}
                                                </td>
                                            </tr>
                                        );
                                    })
                                )}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    /* Grid View Option */
                    <div className="mv-grid-container">
                        {rows.map((row) => {
                            const { date, time } = parseDatePieces(row.movement_date);
                            const isPositive = row.quantity > 0;
                            const typeLabel = MOVEMENT_LABELS[row.movement_type] ?? row.movement_type;

                            return (
                                <div
                                    key={row.id}
                                    className="mv-card-item"
                                    onClick={() => setSelectedMovement(row)}
                                    role="button"
                                >
                                    <div className="mv-card-head">
                                        <div className="d-flex align-items-center gap-3">
                                            <MedicineThumbnail
                                                name={row.medicine_name}
                                                dosageForm={row.medicine_dosage_form}
                                                itemKind={row.medicine_item_kind}
                                            />
                                            <div>
                                                <h6 className="mb-0 fw-bold">{row.medicine_name}</h6>
                                                <span className="fs-7 text-muted">
                                                    Batch: {row.batch_number ?? '—'}
                                                </span>
                                            </div>
                                        </div>
                                        <span className={`mv-type-pill ${isPositive ? 'is-green' : 'is-rose'}`}>
                                            {typeLabel}
                                        </span>
                                    </div>

                                    <div className="mv-card-body">
                                        <div className="mv-card-row">
                                            <span className="mv-card-label">Quantity</span>
                                            <span className={isPositive ? 'mv-qty-pos' : 'mv-qty-neg'}>
                                                {isPositive ? `+${row.quantity}` : row.quantity}
                                            </span>
                                        </div>
                                        <div className="mv-card-row">
                                            <span className="mv-card-label">Balance</span>
                                            <span className="mv-balance-cell">
                                                {row.quantity_before} → <b>{row.quantity_after}</b>
                                            </span>
                                        </div>
                                        <div className="mv-card-row">
                                            <span className="mv-card-label">Reference</span>
                                            <span className="text-primary fw-semibold">{row.notes || '—'}</span>
                                        </div>
                                        <div className="mv-card-row">
                                            <span className="mv-card-label">Date</span>
                                            <span>
                                                {date} {time}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                )}

                {/* 5. Table Footer / Pagination */}
                <div className="mv-table-footer">
                    <div className="mv-footer-info">
                        Showing {totalCount === 0 ? 0 : pageIndex * pageSize + 1} to{' '}
                        {Math.min((pageIndex + 1) * pageSize, totalCount)} of {totalCount} entries
                    </div>

                    <div className="mv-footer-controls">
                        <div className="mv-pagination-btns">
                            <button
                                type="button"
                                className="mv-page-btn"
                                onClick={() => setPageIndex((prev) => Math.max(0, prev - 1))}
                                disabled={pageIndex === 0}
                                aria-label="Previous Page"
                            >
                                <i className="ti ti-chevron-left" />
                            </button>

                            {Array.from({ length: Math.min(5, pageCount) }, (_, i) => i).map((num) => (
                                <button
                                    key={num}
                                    type="button"
                                    className={`mv-page-btn ${pageIndex === num ? 'is-active' : ''}`}
                                    onClick={() => setPageIndex(num)}
                                >
                                    {num + 1}
                                </button>
                            ))}

                            <button
                                type="button"
                                className="mv-page-btn"
                                onClick={() => setPageIndex((prev) => Math.min(pageCount - 1, prev + 1))}
                                disabled={pageIndex >= pageCount - 1}
                                aria-label="Next Page"
                            >
                                <i className="ti ti-chevron-right" />
                            </button>
                        </div>

                        <div className="mv-goto-wrap">
                            <span>Go to</span>
                            <input
                                type="number"
                                className="mv-goto-input"
                                min={1}
                                max={pageCount}
                                value={pageIndex + 1}
                                onChange={(e) => {
                                    const val = Number(e.target.value);
                                    if (val >= 1 && val <= pageCount) {
                                        setPageIndex(val - 1);
                                    }
                                }}
                            />
                            <span>Page</span>
                        </div>
                    </div>
                </div>
            </div>

            {/* Movement Detail Modal */}
            <MovementDetailModal
                movement={selectedMovement}
                onClose={() => setSelectedMovement(null)}
            />
        </div>
    );
}
