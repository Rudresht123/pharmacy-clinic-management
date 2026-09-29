import { useMemo, useState } from 'react';
import { createColumnHelper, type ColumnDef } from '@tanstack/react-table';
import { useNavigate } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import { DataTable } from '@/shared/components/ui/DataTable';
import { LoadingBlock } from '@/shared/components/ui/Feedback';
import { FilterPanel, type FilterField } from '@/shared/components/ui/FilterPanel';
import { useServerTable } from '@/shared/hooks/useServerTable';
import { DASH, formatDateTime, orDash } from '@/shared/utils/format';
import { NoStores, useChosenStore } from '../components/StorePicker';
import {
    MOVEMENT_LABELS,
    useMovements,
    useMovementsSummary,
    type MovementsTotals,
    type StockMovement,
} from '../inventory';

const count = (value: number) => value.toLocaleString('en-IN');

function delta(now: number, before: number): number | null {
    return before > 0 ? Math.round(((now - before) / before) * 100) : null;
}

/** First letters of up to two words — what an avatar shows without a photo. */
function initials(name: string): string {
    const parts = name.trim().split(/\s+/);

    return ((parts[0]?.[0] ?? '') + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase();
}

const REASON_LIMIT = 40;

/** A reason long enough to widen the column is truncated with its own toggle, not left to force the row wide. */
function ReasonCell({ reason }: { reason: string | null }) {
    const [expanded, setExpanded] = useState(false);

    if (!reason) {
        return <span className="text-muted">{DASH}</span>;
    }

    const isLong = reason.length > REASON_LIMIT;

    return (
        <span className="mv-reason">
            {expanded || !isLong ? reason : `${reason.slice(0, REASON_LIMIT).trimEnd()}…`}
            {isLong && (
                <button
                    type="button"
                    className="mv-reason-toggle"
                    onClick={() => setExpanded((value) => !value)}
                >
                    {expanded ? 'Show less' : 'Read more'}
                </button>
            )}
        </span>
    );
}

/** Builds the CSV the "Export" button downloads — the rows on screen, as they read. */
function exportCsv(rows: StockMovement[]) {
    const header = ['Date & time', 'Medicine', 'Batch', 'Movement', 'Quantity', 'Before', 'After', 'Reference', 'Reason', 'By'];

    const lines = rows.map((row) => [
        formatDateTime(row.movement_date),
        row.medicine_name ?? '',
        row.batch_number ?? '',
        MOVEMENT_LABELS[row.movement_type] ?? row.movement_type,
        String(row.quantity),
        String(row.quantity_before),
        String(row.quantity_after),
        row.notes ?? '',
        row.reason ?? '',
        row.performed_by_name ?? 'System',
    ]);

    const csv = [header, ...lines]
        .map((line) => line.map((cell) => `"${cell.replace(/"/g, '""')}"`).join(','))
        .join('\n');

    const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = `stock-movements-${new Date().toISOString().slice(0, 10)}.csv`;
    link.click();
    URL.revokeObjectURL(url);
}

/**
 * A store's ledger, newest first.
 *
 * Read-only by design. A wrong row is answered by a correcting row, which
 * shows here beside the one it corrects.
 */
export default function MovementsPage() {
    const navigate = useNavigate();
    const { stores, store, choose, isLoading: storesLoading } = useChosenStore();

    const [filters, setFilters] = useState<Record<string, string>>({});
    const table = useServerTable({ pageSize: 50, sort: 'id', direction: 'desc' });

    const [from, to] = (filters.date ?? '').split('_');

    const listParams = {
        ...table.params,
        movement_type: filters.movement_type,
        from: from || undefined,
        to: to || undefined,
    };

    // The summary ignores free text — "how much, filtered this way" rather
    // than "how much of what this search term happened to match" — so it
    // reads everything from `listParams` except the search term.
    const summaryParams = { movement_type: filters.movement_type, from: from || undefined, to: to || undefined };

    const { data: page, isLoading, isFetching, isError, refetch } = useMovements(store?.id, listParams);
    const { data: summary } = useMovementsSummary(store?.id, summaryParams);

    const columns = useMemo(() => {
        const column = createColumnHelper<StockMovement>();

        return [
            column.display({
                id: 'row_number',
                header: '#',
                meta: { label: '#' },
                cell: (info) => table.pageIndex * table.pageSize + info.row.index + 1,
            }),
            column.display({
                id: 'movement_date',
                header: 'Date & Time',
                meta: { label: 'Date & Time' },
                cell: (info) => formatDateTime(info.row.original.movement_date),
            }),
            column.display({
                id: 'medicine',
                header: 'Medicine',
                meta: { label: 'Medicine' },
                cell: (info) => (
                    <div className="mv-medicine">
                        <span className="mv-medicine-icon" aria-hidden="true">
                            <i className="ti ti-pill" />
                        </span>
                        <div>
                            <b className="d-block">{info.row.original.medicine_name}</b>
                            <span className="dr-sub">Batch {info.row.original.batch_number ?? '—'}</span>
                        </div>
                    </div>
                ),
            }),
            column.display({
                id: 'movement_type',
                header: 'Movement Type',
                meta: { label: 'Movement Type' },
                cell: (info) => {
                    const tone = info.row.original.quantity > 0 ? 'is-green' : 'is-rose';

                    return (
                        <span className={`bod-type-tag ${tone}`}>
                            {MOVEMENT_LABELS[info.row.original.movement_type] ?? info.row.original.movement_type}
                        </span>
                    );
                },
            }),
            column.display({
                id: 'quantity',
                header: 'Quantity',
                meta: { label: 'Quantity' },
                cell: (info) => (
                    <span
                        className={`fw-semibold tabular-nums ${info.row.original.quantity > 0 ? 'text-success' : 'text-danger'}`}
                    >
                        {info.row.original.quantity > 0 ? '+' : '−'}
                        {Math.abs(info.row.original.quantity)}
                    </span>
                ),
            }),
            column.display({
                id: 'balance',
                header: 'Balance (Before → After)',
                meta: { label: 'Balance' },
                cell: (info) => (
                    <span className="tabular-nums text-muted">
                        {info.row.original.quantity_before} → <b>{info.row.original.quantity_after}</b>
                    </span>
                ),
            }),
            column.display({
                id: 'reference',
                header: 'Reference',
                meta: { label: 'Reference' },
                cell: (info) =>
                    info.row.original.notes ? (
                        <span className="mv-reference">{info.row.original.notes}</span>
                    ) : (
                        orDash(null)
                    ),
            }),
            column.display({
                id: 'reason',
                header: 'Reason',
                meta: { label: 'Reason' },
                cell: (info) => <ReasonCell reason={info.row.original.reason} />,
            }),
            column.display({
                id: 'performed_by_name',
                header: 'By',
                meta: { label: 'By' },
                cell: (info) => {
                    const name = info.row.original.performed_by_name;

                    return (
                        <div className="mv-by">
                            <span className="mv-avatar" aria-hidden="true">
                                {name ? initials(name) : <i className="ti ti-robot" />}
                            </span>
                            <div>
                                <b className="d-block">{name ?? 'System'}</b>
                                {!name && <span className="dr-sub">Automated</span>}
                            </div>
                        </div>
                    );
                },
            }),
        ] as ColumnDef<StockMovement, unknown>[];
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [table.pageIndex, table.pageSize]);

    const filterFields = useMemo<FilterField[]>(
        () => [
            {
                kind: 'select',
                name: 'store',
                label: 'Store',
                value: store ? String(store.id) : '',
                anyLabel: 'Choose a store',
                options: stores.map((candidate) => ({
                    value: String(candidate.id),
                    label: candidate.is_default ? `${candidate.name} (default)` : candidate.name,
                })),
            },
            {
                kind: 'select',
                name: 'movement_type',
                label: 'Movement Type',
                anyLabel: 'Every movement',
                value: filters.movement_type ?? '',
                options: Object.entries(MOVEMENT_LABELS).map(([value, label]) => ({ value, label })),
            },
            {
                kind: 'daterange',
                name: 'date',
                label: 'Date Range',
                value: filters.date ?? '',
            },
            {
                kind: 'text',
                name: 'search',
                label: 'Search',
                placeholder: 'Search medicine, batch, GRN…',
                value: table.search,
            },
        ],
        [filters, store, stores, table.search],
    );

    if (storesLoading) {
        return <LoadingBlock label="Loading stores…" />;
    }

    return (
        <>
            <PageHeader
                title="Inventory Movement"
                subtitle="Track every stock change across your pharmacy."
                home={false}
                actions={
                    store && (
                        <>
                            <Button
                                variant="light"
                                icon="ti ti-download"
                                onClick={() => exportCsv(page?.data ?? [])}
                                disabled={!page?.data.length}
                            >
                                Export
                            </Button>

                            <Button icon="ti ti-plus" onClick={() => navigate('/pharmacy/inwards/create')}>
                                Add Stock
                            </Button>
                        </>
                    )
                }
            />

            {!store ? (
                <Card>
                    <NoStores />
                </Card>
            ) : (
                <>
                    <MovementKpis summary={summary} />

                    <FilterPanel
                        fields={filterFields}
                        onChange={(name, value) => {
                            if (name === 'store') {
                                if (value) choose(Number(value));
                                return;
                            }

                            if (name === 'search') {
                                table.setSearch(value);
                                return;
                            }

                            setFilters((current) => {
                                const next = { ...current };

                                if (value === '') {
                                    delete next[name];
                                } else {
                                    next[name] = value;
                                }

                                return next;
                            });
                        }}
                        onClear={() => {
                            setFilters({});
                            table.setSearch('');
                        }}
                    />

                    <Card>
                        <DataTable
                            data={page?.data ?? []}
                            columns={columns}
                            loading={isLoading}
                            fetching={isFetching}
                            error={isError}
                            onRetry={refetch}
                            hideSearch
                            server={{
                                ...table,
                                total: page?.meta.total ?? 0,
                                pageCount: page?.meta.last_page ?? 1,
                            }}
                            emptyIcon="ti ti-list-numbers"
                            emptyTone="teal"
                            emptyTitle="No movements yet"
                            emptyDescription="Receiving, adjusting, transferring and dispensing all write here."
                        />
                    </Card>
                </>
            )}
        </>
    );
}

function MovementKpis({ summary }: { summary: (MovementsTotals & { previous: MovementsTotals }) | undefined }) {
    if (!summary) {
        return null;
    }

    const { previous } = summary;

    const cards = [
        {
            label: 'Total Movements',
            value: count(summary.count),
            icon: 'ti ti-package',
            cls: 'is-purple',
            sub: 'vs previous period',
            delta: delta(summary.count, previous.count),
        },
        {
            label: 'Units Added',
            value: count(summary.added),
            icon: 'ti ti-trending-up',
            cls: 'is-green',
            sub: 'From purchases & returns',
            delta: delta(summary.added, previous.added),
        },
        {
            label: 'Units Deducted',
            value: count(summary.deducted),
            icon: 'ti ti-trending-down',
            cls: 'is-orange',
            sub: 'From sales & adjustments',
            delta: delta(summary.deducted, previous.deducted),
            riseIsBad: true,
        },
        {
            label: 'Net Stock Change',
            value: `${summary.net >= 0 ? '+' : ''}${count(summary.net)}`,
            icon: 'ti ti-chart-bar',
            cls: 'is-blue',
            sub: 'Current period',
            delta: delta(summary.net, previous.net),
        },
    ];

    return (
        <div className="bod-kpi-grid mb-3">
            {cards.map((card) => {
                const tone =
                    card.delta === null
                        ? null
                        : (card.delta >= 0) !== Boolean(card.riseIsBad)
                          ? 'is-up'
                          : 'is-up-danger';

                return (
                    <div className={`bod-kpi-card ${card.cls}`} key={card.label}>
                        <div className="bod-kpi-head">
                            <span className={`bod-kpi-icon ${card.cls}`}>
                                <i className={card.icon} aria-hidden="true" />
                            </span>
                            <span className="bod-kpi-label">{card.label}</span>
                        </div>

                        <div className="bod-kpi-value-row">
                            <span className="bod-kpi-value">{card.value}</span>
                            {tone && card.delta !== null && (
                                <span className={`bod-kpi-delta ${tone}`}>
                                    <i className={`ti ti-arrow-${card.delta >= 0 ? 'up' : 'down'}-right`} />
                                    {Math.abs(card.delta)}%
                                </span>
                            )}
                        </div>

                        <span className="bod-kpi-sub">{card.sub}</span>
                    </div>
                );
            })}
        </div>
    );
}
