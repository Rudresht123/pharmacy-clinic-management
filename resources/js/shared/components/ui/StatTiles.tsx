import type { ReactNode } from 'react';
import { cn } from '@/shared/utils/cn';
import type { Tone } from './tones';

export interface StatTile {
    label: string;
    value: ReactNode;
    /** Tabler icon class, e.g. "ti ti-users". */
    icon?: string;
    tone?: Tone;
    /** One line under the number — a breakdown, a delta, a caveat. */
    hint?: string;
    /** Makes the tile a filter toggle rather than a read-out. */
    onClick?: () => void;
    active?: boolean;
}

/**
 * The row of figures a list screen leads with.
 *
 * Shared so every screen's header reads the same way, and so a tile that
 * doubles as a filter behaves identically wherever it appears.
 */
export function StatTiles({ tiles, loading }: { tiles: StatTile[]; loading?: boolean }) {
    return (
        <div className="stat-tiles">
            {tiles.map((tile) => {
                const interactive = Boolean(tile.onClick);

                const content = (
                    <>
                        {tile.icon && (
                            <span className="stat-tile-icon" aria-hidden="true">
                                <i className={tile.icon} />
                            </span>
                        )}

                        <span className="stat-tile-body">
                            <span className="stat-tile-value">
                                {loading ? <span className="stat-tile-skeleton" /> : tile.value}
                            </span>
                            <span className="stat-tile-label">{tile.label}</span>
                            {tile.hint && <span className="stat-tile-hint">{tile.hint}</span>}
                        </span>
                    </>
                );

                const className = cn(
                    'stat-tile',
                    tile.tone && `tone-${tile.tone}`,
                    interactive && 'is-interactive',
                    tile.active && 'is-active',
                );

                return interactive ? (
                    <button
                        key={tile.label}
                        type="button"
                        className={className}
                        onClick={tile.onClick}
                        aria-pressed={tile.active}
                    >
                        {content}
                    </button>
                ) : (
                    <div key={tile.label} className={className}>
                        {content}
                    </div>
                );
            })}
        </div>
    );
}
