import type { ReactNode } from 'react';
import { Card } from '@/shared/components/ui/Card';
import { StatTiles, type StatTile } from '@/shared/components/ui/StatTiles';

/**
 * Sent, delivered, read, failed — the four figures every channel answers with.
 *
 * In that order deliberately: each one is a subset of the one before it, so
 * the row reads left to right as a funnel narrowing, and the last tile is the
 * only one anybody has to act on.
 *
 * Bare on WhatsApp, where it heads the page; inside a titled card on email,
 * where it sits beside a date range and needs to say what period it covers.
 */
export function ChannelStats({
    tiles,
    title,
    description,
    actions,
}: {
    tiles: StatTile[];
    title?: string;
    description?: string;
    actions?: ReactNode;
}) {
    if (!title) {
        return (
            <div className="comm-tiles">
                <StatTiles tiles={tiles} />
            </div>
        );
    }

    return (
        <Card
            className="comm-card"
            title={title}
            icon="ti ti-chart-bar"
            description={description}
            actions={actions}
        >
            <div className="comm-tiles is-inset">
                <StatTiles tiles={tiles} />
            </div>
        </Card>
    );
}
