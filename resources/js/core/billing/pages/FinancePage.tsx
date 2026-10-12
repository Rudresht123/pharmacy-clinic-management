import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { EmptyState, ErrorState, LoadingBlock } from '@/shared/components/ui/Feedback';
import { Pagination } from '@/shared/components/ui/Pagination';
import { useDebounce } from '@/shared/hooks/useDebounce';
import { BranchFilter } from '@/core/locations/components/BranchFilter';
import {
    PAYMENT_METHOD_LABELS,
    useFinanceDocuments,
    useFinanceSummary,
    type FinanceFigures,
    type FinanceModule,
} from '../api';
import { DateRangePicker, currentMonthWindow, formatDisplayDate, moneyExact } from '../components/BillingWidgets';

const MODULE_META: Record<FinanceModule, { label: string; icon: string; tone: string }> = {
    opd: { label: 'OPD', icon: 'ti ti-stethoscope', tone: 'is-blue' },
    laboratory: { label: 'Laboratory', icon: 'ti ti-flask', tone: 'is-purple' },
    pharmacy: { label: 'Pharmacy', icon: 'ti ti-pill', tone: 'is-green' },
};

const STATUS_LABELS: Record<string, string> = {
    pending: 'Unpaid',
    unpaid: 'Unpaid',
    partially_paid: 'Part paid',
    partial: 'Part paid',
    paid: 'Paid',
    refunded: 'Refunded',
    partially_refunded: 'Part refunded',
    cancelled: 'Cancelled',
    billed_via_invoice: 'On visit bill',
};

type Tab = 'all' | FinanceModule;

const PAGE_SIZE = 25;

/**
 * Finance — every department's money, read together.
 *
 * NOT A TILL. The OPD, the lab and the pharmacy each take their own money at
 * their own counters; this reads both registers side by side for whoever
 * runs the place.
 *
 * FIVE FIGURES, NEVER CONFUSED. What bills were raised for, what came back as
 * goods, what was taken, what was handed back, and what is owed now — with
 * net collections the difference of the middle two. Money is counted from
 * payment rows only, on the day it moved, so nothing is counted twice: not a
 * refund, not a pharmacy sale, not a bill that was later cancelled.
 *
 * Old figures stay readable when a department is switched off; its row is
 * simply quieter.
 */
export default function FinancePage() {
    const [range, setRange] = useState(currentMonthWindow);
    const [tab, setTab] = useState<Tab>('all');
    const [owingOnly, setOwingOnly] = useState(false);
    const [search, setSearch] = useState('');
    const [branch, setBranch] = useState('');
    const [page, setPage] = useState(1);

    const debouncedSearch = useDebounce(search);

    useEffect(() => setPage(1), [tab, owingOnly, debouncedSearch, branch, range.from, range.to]);

    const summary = useFinanceSummary({
        from: range.from,
        to: range.to,
        location_id: branch ? Number(branch) : undefined,
    });
    const documents = useFinanceDocuments({
        page,
        per_page: PAGE_SIZE,
        from: owingOnly ? undefined : range.from,
        to: owingOnly ? undefined : range.to,
        location_id: branch ? Number(branch) : undefined,
        ...(tab === 'all' ? {} : { module: tab }),
        ...(owingOnly ? { outstanding_only: 1 as const } : {}),
        ...(debouncedSearch.trim() ? { search: debouncedSearch.trim() } : {}),
    });

    const data = summary.data;

    // A department is shown if it runs now, or if it has anything to show.
    const modules = (data?.modules ?? []).filter(
        (module) => module.key === 'opd' || data?.departments[module.key] || module.documents > 0 || module.collected > 0 || module.outstanding > 0,
    );

    return (
        <div className="inv-page">
            <PageHeader
                title="Finance"
                subtitle="Every department's money in one place — billed, collected, refunded and owed."
                icon="ti ti-chart-pie"
                tone="teal"
                crumbs={[{ label: 'Billing', to: '/billing' }, { label: 'Finance' }]}
                actions={
                    <div className="bd-header-actions">
                        <BranchFilter value={branch} onChange={setBranch} />
                        <DateRangePicker value={range} onChange={setRange} />
                    </div>
                }
            />

            {summary.isLoading ? (
                <LoadingBlock label="Adding it up…" />
            ) : summary.isError || !data ? (
                <ErrorState onRetry={() => summary.refetch()} />
            ) : (
                <>
                    <div className="inv-kpis">
                        <Kpi icon="ti ti-file-invoice" tone="is-blue" label="Billed" value={data.totals.billed} hint={`${data.totals.documents} bills raised`} />
                        <Kpi icon="ti ti-cash" tone="is-teal" label="Collected" value={data.totals.collected} hint="Money taken in this window" />
                        <Kpi icon="ti ti-receipt-refund" tone="is-red" label="Refunded" value={data.totals.refunded} hint="Money handed back" />
                        <Kpi icon="ti ti-scale" tone="is-green" label="Net collections" value={data.totals.net_collected} hint="Collected less refunded" />
                        <Kpi icon="ti ti-clock-dollar" tone="is-orange" label="Outstanding" value={data.totals.outstanding} hint="Owed today, whenever billed" />
                    </div>

                    <p className="fs-13 text-muted mb-3">
                        <i className="ti ti-info-circle me-1" aria-hidden="true" />
                        Billed is what bills were raised for; collected and refunded are the money that actually moved,
                        on the day it moved. A bill is never counted as collected until it is paid.
                        {data.totals.credit_notes > 0 && (
                            <>
                                {' '}
                                Goods worth <b>{moneyExact(data.totals.credit_notes)}</b> came back at the pharmacy.
                            </>
                        )}
                    </p>

                    <div className="row g-3 mb-3">
                        <div className="col-xl-7">
                            <FiguresTable
                                title="By department"
                                rows={modules.map((module) => ({
                                    key: module.key,
                                    label: (
                                        <span className="d-inline-flex align-items-center gap-2">
                                            <span className={`inv-type-icon ${MODULE_META[module.key].tone}`} aria-hidden="true">
                                                <i className={MODULE_META[module.key].icon} />
                                            </span>
                                            {module.label}
                                            {!data.departments[module.key] && (
                                                <span className="inv-kind" title="Not running now — its history stays readable">
                                                    Switched off
                                                </span>
                                            )}
                                        </span>
                                    ),
                                    figures: module,
                                }))}
                            />
                        </div>

                        <div className="col-xl-5">
                            <div className="inv-card h-100">
                                <h6 className="mb-3">By payment method</h6>
                                {data.methods.length === 0 ? (
                                    <p className="text-muted fs-13 mb-0">No money moved in this window.</p>
                                ) : (
                                    <div className="inv-table-wrap">
                                        <table className="inv-table">
                                            <thead>
                                                <tr>
                                                    <th>Method</th>
                                                    <th className="text-end">Collected</th>
                                                    <th className="text-end">Refunded</th>
                                                    <th className="text-end">Net</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {data.methods.map((row) => (
                                                    <tr key={row.method}>
                                                        <td>{PAYMENT_METHOD_LABELS[row.method] ?? row.method}</td>
                                                        <td className="text-end tabular-nums">{moneyExact(row.collected)}</td>
                                                        <td className="text-end tabular-nums">{moneyExact(row.refunded)}</td>
                                                        <td className="text-end tabular-nums fw-semibold">{moneyExact(row.net)}</td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>

                    {data.branches.length > 1 && (
                        <div className="mb-3">
                            <FiguresTable
                                title="By branch"
                                rows={data.branches.map((branch) => ({
                                    key: String(branch.location_id),
                                    label: branch.name,
                                    figures: branch,
                                }))}
                            />
                        </div>
                    )}

                    <div className="inv-card mb-3">
                        <h6 className="mb-3">{data.granularity === 'day' ? 'Day by day' : 'Month by month'}</h6>
                        <div className="inv-table-wrap">
                            <table className="inv-table">
                                <thead>
                                    <tr>
                                        <th>{data.granularity === 'day' ? 'Day' : 'Month'}</th>
                                        <th className="text-end">Billed</th>
                                        <th className="text-end">Collected</th>
                                        <th className="text-end">Refunded</th>
                                        <th className="text-end">Net</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {data.series
                                        .filter((row) => row.billed || row.collected || row.refunded)
                                        .map((row) => (
                                            <tr key={row.key}>
                                                <td>{row.label}</td>
                                                <td className="text-end tabular-nums">{moneyExact(row.billed)}</td>
                                                <td className="text-end tabular-nums">{moneyExact(row.collected)}</td>
                                                <td className="text-end tabular-nums">{moneyExact(row.refunded)}</td>
                                                <td className="text-end tabular-nums fw-semibold">{moneyExact(row.net)}</td>
                                            </tr>
                                        ))}
                                </tbody>
                            </table>
                        </div>
                        {data.series.every((row) => !row.billed && !row.collected && !row.refunded) && (
                            <p className="text-muted fs-13 mt-2 mb-0">Nothing was billed or collected in this window.</p>
                        )}
                    </div>
                </>
            )}

            <div className="inv-tabs">
                {(['all', 'opd', 'laboratory', 'pharmacy'] as Tab[]).map((value) => (
                    <button
                        key={value}
                        type="button"
                        className={`inv-tab${tab === value ? ' is-active' : ''}`}
                        onClick={() => setTab(value)}
                    >
                        {value === 'all' ? 'All bills' : MODULE_META[value].label}
                    </button>
                ))}
            </div>

            <div className="inv-card">
                <div className="inv-filters">
                    <label className="inv-search">
                        <i className="ti ti-search" aria-hidden="true" />
                        <input
                            type="search"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Search by bill number or patient…"
                        />
                    </label>

                    <label className="form-check form-switch mb-0 d-flex align-items-center gap-2">
                        <input
                            type="checkbox"
                            className="form-check-input"
                            checked={owingOnly}
                            onChange={(event) => setOwingOnly(event.target.checked)}
                        />
                        <span className="fs-13">Only bills still owed (any date)</span>
                    </label>
                </div>

                {documents.isLoading ? (
                    <LoadingBlock label="Loading bills…" />
                ) : documents.isError ? (
                    <ErrorState onRetry={() => documents.refetch()} />
                ) : (documents.data?.data ?? []).length === 0 ? (
                    <EmptyState
                        icon="ti ti-file-invoice"
                        tone="teal"
                        title="No bills here"
                        description="Bills from the OPD, the lab and the pharmacy appear here as they are raised."
                    />
                ) : (
                    <>
                        <div className={`inv-table-wrap${documents.isFetching ? ' is-refreshing' : ''}`}>
                            <table className="inv-table">
                                <thead>
                                    <tr>
                                        <th>Bill</th>
                                        <th>Date</th>
                                        <th>Department</th>
                                        <th>Patient</th>
                                        <th className="text-end">Total</th>
                                        <th className="text-end">Paid</th>
                                        <th className="text-end">Refunded / returned</th>
                                        <th className="text-end">Owed</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {documents.data!.data.map((row) => (
                                        <tr key={`${row.source}-${row.id}`}>
                                            <td>
                                                {row.source === 'invoice' ? (
                                                    <Link to={`/billing/invoices/${row.id}`} className="inv-number">
                                                        {row.number}
                                                    </Link>
                                                ) : (
                                                    <span className="inv-number">{row.number}</span>
                                                )}
                                            </td>
                                            <td>{formatDisplayDate(row.date.slice(0, 10))}</td>
                                            <td>{MODULE_META[row.module].label}</td>
                                            <td>{row.party}</td>
                                            <td className="text-end tabular-nums">{moneyExact(row.total)}</td>
                                            <td className="text-end tabular-nums">{moneyExact(row.paid)}</td>
                                            <td className="text-end tabular-nums">{row.credited > 0 ? moneyExact(row.credited) : '—'}</td>
                                            <td className="text-end tabular-nums fw-semibold">
                                                {row.outstanding > 0 ? moneyExact(row.outstanding) : '—'}
                                            </td>
                                            <td>{STATUS_LABELS[row.status] ?? row.status.replace(/_/g, ' ')}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        {documents.data && (
                            <Pagination
                                page={documents.data.meta.current_page}
                                pageCount={documents.data.meta.last_page}
                                total={documents.data.meta.total}
                                perPage={documents.data.meta.per_page}
                                onChange={setPage}
                            />
                        )}
                    </>
                )}
            </div>
        </div>
    );
}

function Kpi({ icon, tone, label, value, hint }: { icon: string; tone: string; label: string; value: number; hint: string }) {
    return (
        <div className="inv-kpi">
            <span className={`inv-kpi-icon ${tone}`} aria-hidden="true">
                <i className={icon} />
            </span>
            <div className="inv-kpi-body">
                <span className="inv-kpi-label">{label}</span>
                <div className="inv-kpi-row">
                    <b className="inv-kpi-value">{moneyExact(value)}</b>
                </div>
                <span className="inv-kpi-change-line">{hint}</span>
            </div>
        </div>
    );
}

function FiguresTable({
    title,
    rows,
}: {
    title: string;
    rows: { key: string; label: React.ReactNode; figures: FinanceFigures }[];
}) {
    return (
        <div className="inv-card h-100">
            <h6 className="mb-3">{title}</h6>
            <div className="inv-table-wrap">
                <table className="inv-table">
                    <thead>
                        <tr>
                            <th />
                            <th className="text-end">Bills</th>
                            <th className="text-end">Billed</th>
                            <th className="text-end">Collected</th>
                            <th className="text-end">Refunded</th>
                            <th className="text-end">Net</th>
                            <th className="text-end">Outstanding</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row) => (
                            <tr key={row.key}>
                                <td>{row.label}</td>
                                <td className="text-end tabular-nums">{row.figures.documents}</td>
                                <td className="text-end tabular-nums">{moneyExact(row.figures.billed)}</td>
                                <td className="text-end tabular-nums">{moneyExact(row.figures.collected)}</td>
                                <td className="text-end tabular-nums">{moneyExact(row.figures.refunded)}</td>
                                <td className="text-end tabular-nums fw-semibold">{moneyExact(row.figures.net_collected)}</td>
                                <td className="text-end tabular-nums">{moneyExact(row.figures.outstanding)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
