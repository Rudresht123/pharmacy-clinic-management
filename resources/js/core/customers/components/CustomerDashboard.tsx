import { useMemo, useState } from 'react';
import { Card } from '@/shared/components/ui/Card';
import { StatTiles } from '@/shared/components/ui/StatTiles';
import { BarList } from '@/shared/components/ui/BarList';
import { TrendBars } from '@/shared/components/ui/TrendBars';
import { TrendRows } from '@/shared/components/ui/TrendRows';
import { AreaChart } from '@/shared/components/ui/AreaChart';
import { DonutChart } from '@/shared/components/ui/DonutChart';
import { Tabs } from '@/shared/components/ui/Tabs';
import type { ConfigurableField } from '@/core/field-settings/types';
import type { CustomerStats } from '../types';

interface CustomerDashboardProps {
    stats?: CustomerStats;
    loading: boolean;
    /** The effective schema, so hidden fields do not get charted. */
    fields?: ConfigurableField[];
    labels: { singular: string; plural: string };
    /** Sends the reader to the listing with a status already applied. */
    onDrillDown: (status: 'active' | 'inactive') => void;
}

type Series = 'new' | 'total';

/** The headline the chart is really about, set beside its caption. */
function Figure({ value, caption }: { value: number; caption: string }) {
    return (
        <span className="chart-figure">
            <b>{value}</b>
            <span>{caption}</span>
        </span>
    );
}

/**
 * What the organization looks like, before anyone reads a single row.
 *
 * Each card answers one question and plots one series. Nothing here is a
 * second view of the table — the listing tab is where individual people are
 * found, and the two are deliberately not the same screen.
 */
export function CustomerDashboard({
    stats,
    loading,
    fields,
    labels,
    onDrillDown,
}: CustomerDashboardProps) {
    const [series, setSeries] = useState<Series>('new');

    /*
     * A field an organization has switched off should not come back as a
     * chart. Looking it up by key also means a relabelled field titles its
     * own card: rename "Gender" to "Sex" and the heading follows.
     */
    const shown = useMemo(() => {
        const byKey = new Map((fields ?? []).map((field) => [field.key, field]));

        return (key: string) => {
            const field = byKey.get(key);

            return field?.show_in_form === false ? null : (field ?? null);
        };
    }, [fields]);

    const gender = shown('gender');
    const dob = shown('date_of_birth');
    const city = shown('city');

    const months = stats?.by_month ?? [];
    const thisMonth = months.length > 0 ? months[months.length - 1] : null;

    const tiles = useMemo(() => {
        const busiest = (stats?.by_location ?? [])
            .filter((entry) => entry.location_id !== null)
            .sort((a, b) => b.total - a.total)[0];

        return [
            {
                label: `Total ${labels.plural.toLowerCase()}`,
                value: stats?.total ?? 0,
                icon: 'ti ti-users',
                tone: 'sky' as const,
                hint: 'Across every branch',
            },
            {
                label: 'Active',
                value: stats?.active ?? 0,
                icon: 'ti ti-user-check',
                tone: 'emerald' as const,
                // Opens the listing already filtered, rather than filtering a
                // table the reader cannot see from here.
                onClick: () => onDrillDown('active'),
            },
            {
                label: 'Inactive',
                value: stats?.inactive ?? 0,
                icon: 'ti ti-user-off',
                tone: 'rose' as const,
                onClick: () => onDrillDown('inactive'),
            },
            {
                label: 'Added in 30 days',
                value: stats?.recent ?? 0,
                icon: 'ti ti-user-plus',
                tone: 'violet' as const,
                hint: busiest ? `Busiest branch: ${busiest.label}` : undefined,
            },
        ];
    }, [stats, labels.plural, onDrillDown]);

    const people = labels.plural.toLowerCase();

    return (
        <>
            <StatTiles tiles={tiles} loading={loading} />

            <div className="row g-3">
                <div className="col-12 col-xl-8">
                    <Card
                        className="chart-card"
                        title="Growth"
                        icon="ti ti-chart-line"
                        actions={
                            <Tabs<Series>
                                size="sm"
                                label="Which series to plot"
                                value={series}
                                onChange={setSeries}
                                tabs={[
                                    { value: 'new', label: 'New' },
                                    { value: 'total', label: 'Total' },
                                ]}
                            />
                        }
                    >
                        <div className="chart-head">
                            <p className="area-caption mb-0">
                                {series === 'new'
                                    ? `New ${people} each month, last twelve.`
                                    : `The book of ${people} as it grew, last twelve months.`}
                            </p>

                            <Figure
                                value={
                                    series === 'new'
                                        ? (thisMonth?.total ?? 0)
                                        : (thisMonth?.running ?? 0)
                                }
                                caption={series === 'new' ? 'this month' : 'to date'}
                            />
                        </div>

                        <AreaChart
                            valueLabel={series === 'new' ? 'joined' : 'on file'}
                            points={months.map((entry) => ({
                                label: entry.label,
                                value: series === 'new' ? entry.total : entry.running,
                                title: entry.month,
                            }))}
                        />
                    </Card>
                </div>

                <div className="col-lg-6 col-xl-4">
                    <Card
                        className="chart-card"
                        title="Where they registered"
                        icon="ti ti-building-store"
                        description="Everyone stays visible to every branch."
                    >
                        <DonutChart
                            centreLabel={people}
                            slices={(stats?.by_location ?? []).map((entry) => ({
                                label: entry.label,
                                value: entry.total,
                                // "Not recorded" is an absence, not a branch.
                                muted: entry.location_id === null,
                            }))}
                            empty={`No ${people} have been registered yet.`}
                        />
                    </Card>
                </div>

                <div className="col-12 col-xl-8">
                    <Card
                        className="chart-card"
                        title="How each branch is growing"
                        icon="ti ti-timeline"
                        description="One line per branch, each scaled to itself — compare the shape, read the number."
                    >
                        <TrendRows
                            rows={(stats?.by_branch_month ?? []).map((entry) => ({
                                label: entry.label,
                                total: entry.total,
                                points: entry.points,
                                muted: entry.location_id === null,
                            }))}
                            empty="Nobody has joined in the last twelve months."
                        />
                    </Card>
                </div>

                <div className="col-lg-6 col-xl-4">
                    <Card
                        className="chart-card"
                        title="Busiest days"
                        icon="ti ti-calendar-week"
                        description="Which weekday people are signed up on."
                    >
                        <TrendBars
                            points={(stats?.by_weekday ?? []).map((entry) => ({
                                label: entry.label,
                                value: entry.total,
                                title: entry.label,
                            }))}
                        />
                    </Card>
                </div>

                {gender && (
                    <div className="col-lg-6 col-xl-4">
                        <Card
                            className="chart-card"
                            title={`${gender.label} split`}
                            icon="ti ti-venus"
                        >
                            <DonutChart
                                centreLabel={people}
                                slices={(stats?.by_gender ?? []).map((slice) => ({
                                    label: slice.label,
                                    value: slice.total,
                                    muted: slice.muted,
                                }))}
                                empty="Nobody has this recorded yet."
                            />
                        </Card>
                    </div>
                )}

                {dob && (
                    <div className="col-lg-6 col-xl-4">
                        <Card
                            className="chart-card"
                            title="Age bands"
                            icon="ti ti-cake"
                            description="Completed years at today's date."
                        >
                            <BarList
                                // Ordinal: the bands carry their own order.
                                sort={false}
                                max={8}
                                rows={(stats?.by_age_band ?? []).map((slice) => ({
                                    label: slice.label,
                                    value: slice.total,
                                    muted: slice.muted,
                                }))}
                                empty="No dates of birth on file yet."
                            />
                        </Card>
                    </div>
                )}

                {city && (
                    <div className="col-lg-6 col-xl-4">
                        <Card
                            className="chart-card"
                            title={`Top ${city.label.toLowerCase()} areas`}
                            icon="ti ti-map-pin"
                        >
                            <BarList
                                rows={(stats?.by_city ?? []).map((entry) => ({
                                    label: entry.label,
                                    value: entry.total,
                                }))}
                                empty="No addresses on file yet."
                            />
                        </Card>
                    </div>
                )}
            </div>
        </>
    );
}
