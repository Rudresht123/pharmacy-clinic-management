import type { ReactNode } from 'react';
import type { ChannelFigures, PushDeviceSummary } from '../types';

function count(value: number): string {
    return value.toLocaleString('en-IN');
}

/** "↑ 12%" — or nothing, where there is no earlier period to compare with. */
function Change({ value, good = 'up' }: { value: number | null | undefined; good?: 'up' | 'down' }) {
    if (value === null || value === undefined) {
        return null;
    }

    const up = value >= 0;
    const fine = good === 'up' ? up : !up;

    return (
        <span className={`ph-change ${fine ? 'is-good' : 'is-bad'}`}>
            <i className={up ? 'ti ti-arrow-up' : 'ti ti-arrow-down'} aria-hidden="true" />
            {up ? '+' : '−'}
            {Math.abs(value)}%
        </span>
    );
}

/**
 * The four figures across the top: how many phones can be reached, and what
 * happened to what was sent to them.
 *
 * Phones first — on this channel the audience is not a list of numbers the
 * clinic already holds, it is whoever has installed the app, and that is the
 * figure that decides whether anything else here can move.
 */
export function PushKpis({
    figures,
    devices,
    days,
}: {
    figures: ChannelFigures;
    devices: PushDeviceSummary | undefined;
    days: number;
}) {
    const period = `Last ${days} days`;

    const tiles: { key: string; tone: string; icon: string; label: string; value: string; hint: string; change: ReactNode }[] = [
        {
            key: 'devices',
            tone: 'blue',
            icon: 'ti ti-device-mobile',
            label: 'Total devices',
            value: devices ? count(devices.active) : '—',
            hint: devices
                ? `Active device tokens · ${count(devices.android)} Android, ${count(devices.ios)} iPhone`
                : 'Active device tokens',
            change: <Change value={devices?.change} />,
        },
        {
            key: 'sent',
            tone: 'green',
            icon: 'ti ti-send',
            label: 'Notifications sent',
            value: count(figures.sent),
            hint: period,
            change: <Change value={figures.change} />,
        },
        {
            key: 'rate',
            tone: 'orange',
            icon: 'ti ti-chart-bar',
            label: 'Delivery rate',
            value: figures.delivery_rate === null ? '—' : `${figures.delivery_rate}%`,
            hint: figures.sent === 0 ? 'Nothing sent yet' : period,
            change: null,
        },
        {
            key: 'failed',
            tone: 'red',
            icon: 'ti ti-alert-triangle',
            label: 'Failed',
            value: count(figures.failed),
            hint: figures.failure_rate === null ? period : `${figures.failure_rate}% of sent · ${period.toLowerCase()}`,
            change: null,
        },
    ];

    return (
        <div className="ph-stats" aria-label="App notification figures">
            {tiles.map((tile) => (
                <div key={tile.key} className={`ph-stat is-${tile.tone}`}>
                    <span className="ph-stat-icon" aria-hidden="true">
                        <i className={tile.icon} />
                    </span>

                    <div className="ph-stat-body">
                        <span className="ph-stat-label">{tile.label}</span>
                        <span className="ph-stat-value">{tile.value}</span>
                        <span className="ph-stat-hint">{tile.hint}</span>
                    </div>

                    {tile.change}
                </div>
            ))}
        </div>
    );
}
