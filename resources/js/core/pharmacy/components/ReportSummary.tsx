import { Card } from '@/shared/components/ui/Card';
import { BarList } from '@/shared/components/ui/BarList';
import { DonutChart } from '@/shared/components/ui/DonutChart';
import { TrendBars } from '@/shared/components/ui/TrendBars';
import { ErrorState, LoadingBlock } from '@/shared/components/ui/Feedback';
import { Tabs, type TabItem } from '@/shared/components/ui/Tabs';
import { formatDate } from '@/shared/utils/format';
import { PAYMENT_METHOD_LABELS } from '../types';
import type { ReportFigure, ReportSummary as Summary } from '../reports';

/** How a screen is being read: the shape of it, or the rows behind it. */
export type PharmacyView = 'dashboard' | 'table';

export const VIEW_TABS: TabItem<PharmacyView>[] = [
    { value: 'dashboard', label: 'Dashboard', icon: 'ti ti-chart-histogram' },
    { value: 'table', label: 'Table', icon: 'ti ti-table' },
];

/** Rupees, grouped the Indian way — ₹12,48,500 rather than ₹1,248,500. */
export function money(amount: number): string {
    return `₹${Math.round(amount).toLocaleString('en-IN')}`;
}

export function count(value: number): string {
    return value.toLocaleString('en-IN');
}

/** `upi` is how a tender is stored; "UPI" is how it is read. */
export function tenderName(method: string): string {
    return PAYMENT_METHOD_LABELS[method] ?? method;
}

function figureText(figure: ReportFigure): string {
    return figure.money ? money(figure.value) : `${count(figure.value)}${figure.suffix ?? ''}`;
}

/**
 * The Dashboard / Table switch every pharmacy list carries.
 *
 * The same control in the same place on each screen, because it answers the
 * same question everywhere: am I reading the shape of this, or the rows?
 */
export function ViewTabs({ value, onChange }: { value: PharmacyView; onChange: (next: PharmacyView) => void }) {
    return <Tabs tabs={VIEW_TABS} value={value} onChange={onChange} label="How to read it" />;
}

/**
 * A screen's dashboard view: its headline figures, the days behind them, and
 * the split that explains them.
 *
 * Fed by the reports service, so the summary above a table and the report of
 * the same name are the same numbers from the same query — a stock figure
 * that disagreed with the stock screen would make both worthless.
 */
export function ReportSummaryView({
    summary,
    isLoading,
    isError,
    onRetry,
    chartTitle = 'Day by day',
}: {
    summary: Summary | undefined;
    isLoading: boolean;
    isError: boolean;
    onRetry: () => void;
    chartTitle?: string;
}) {
    if (isError) {
        return <ErrorState onRetry={onRetry} />;
    }

    if (isLoading || !summary) {
        return <LoadingBlock label="Adding it up…" />;
    }

    return (
        <>
            <div className="rp-figures">
                {summary.figures.map((figure) => (
                    <Card className="rp-figure" key={figure.label}>
                        <b>{figureText(figure)}</b>
                        <span>{figure.label}</span>
                        {figure.hint && <small>{figure.hint}</small>}
                    </Card>
                ))}
            </div>

            <div className="rp-charts">
                {summary.series.length > 0 && (
                    <Card
                        className="chart-card"
                        title={chartTitle}
                        icon="ti ti-chart-bar"
                        description={`${formatDate(summary.from)} to ${formatDate(summary.to)}`}
                    >
                        <TrendBars
                            points={summary.series.map((point) => ({
                                label: point.label,
                                value: Math.round(point.value),
                                title: `${point.title} · ${money(point.value)}`,
                            }))}
                        />
                    </Card>
                )}

                {summary.splits.map((split) => {
                    const rows = split.rows.map((row) => ({
                        ...row,
                        label: split.tenders ? tenderName(row.label) : row.label,
                    }));

                    // Counted in things, or in rupees.
                    const format = split.money === false ? count : money;

                    return (
                        <Card
                            className="chart-card"
                            key={split.title}
                            title={split.title}
                            icon={split.kind === 'donut' ? 'ti ti-chart-donut' : 'ti ti-chart-bar-horizontal'}
                        >
                            {split.kind === 'donut' ? (
                                <DonutChart
                                    slices={rows}
                                    centreLabel={split.title}
                                    format={format}
                                    empty="Nothing to divide up in this range."
                                />
                            ) : (
                                <BarList
                                    rows={rows}
                                    format={format}
                                    max={8}
                                    empty="Nothing to rank in this range."
                                />
                            )}
                        </Card>
                    );
                })}
            </div>
        </>
    );
}
