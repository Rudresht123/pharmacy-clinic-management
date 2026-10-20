import { cn } from '@/shared/utils/cn';
import type { RecordCard } from '../types';

const LOOK: Record<RecordCard['key'], { icon: string; tone: string }> = {
    total: { icon: 'ti ti-users', tone: 'blue' },
    new: { icon: 'ti ti-user-plus', tone: 'green' },
    upcoming: { icon: 'ti ti-calendar-event', tone: 'rose' },
    recent: { icon: 'ti ti-activity-heartbeat', tone: 'violet' },
};

/**
 * The figures above the register. Only the cards the server sent — one this
 * reader may not see is left out rather than shown at zero.
 */
export function KpiRow({ cards, loading }: { cards: RecordCard[] | undefined; loading: boolean }) {
    if (loading && !cards) {
        return (
            <div className="ptr-kpis is-4" aria-busy="true">
                {[0, 1, 2, 3].map((index) => (
                    <div key={index} className="ptr-kpi is-loading" />
                ))}
            </div>
        );
    }

    if (!cards || cards.length === 0) return null;

    return (
        <div className={`ptr-kpis is-${cards.length}`}>
            {cards.map((card) => {
                const look = LOOK[card.key];
                const up = card.change !== null && card.change > 0;
                const down = card.change !== null && card.change < 0;

                return (
                    <div key={card.key} className={`ptr-kpi is-${look.tone}`}>
                        <span className="ptr-kpi-icon" aria-hidden="true">
                            <i className={look.icon} />
                        </span>

                        <div className="ptr-kpi-body">
                            <span className="ptr-kpi-label">{card.label}</span>
                            <b className="ptr-kpi-value">{card.value.toLocaleString('en-IN')}</b>
                            <span className="ptr-kpi-hint">
                                {card.change !== null && (
                                    <em className={cn(up && 'is-up', down && 'is-down')}>
                                        <i
                                            className={down ? 'ti ti-arrow-down' : up ? 'ti ti-arrow-up' : 'ti ti-minus'}
                                            aria-hidden="true"
                                        />
                                        {up ? '+' : ''}
                                        {card.change}%
                                    </em>
                                )}
                                {card.hint}
                            </span>
                        </div>
                    </div>
                );
            })}
        </div>
    );
}
