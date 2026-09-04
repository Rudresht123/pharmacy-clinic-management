export interface TabItem<T extends string> {
    value: T;
    label: string;
    /** Tabler icon class, e.g. "ti ti-chart-bar". */
    icon?: string;
    /** A count beside the label — omit rather than pass 0 for "unknown". */
    badge?: number | string;
}

/**
 * The strip that switches between views of one screen.
 *
 * A tab is not navigation: it swaps what the page shows without changing
 * what the page is. Callers decide where the selection lives — the settings
 * screen keeps it in the path, the customers screen in a query string — so
 * this component only renders and reports.
 *
 * Styling is the existing .app-tabs / .app-tab pair in vendor/css.
 */
export function Tabs<T extends string>({
    tabs,
    value,
    onChange,
    label,
    size,
}: {
    tabs: readonly TabItem<T>[];
    value: T;
    onChange: (next: T) => void;
    /** Names the group for screen readers, e.g. "Customer views". */
    label: string;
    /** "sm" for a switch riding a card header rather than heading a page. */
    size?: 'sm';
}) {
    return (
        <nav
            className={`app-tabs${size === 'sm' ? ' is-sm' : ''}`}
            role="tablist"
            aria-label={label}
        >
            {tabs.map((tab) => {
                const active = tab.value === value;

                return (
                    <button
                        key={tab.value}
                        type="button"
                        role="tab"
                        aria-selected={active}
                        className={`app-tab${active ? ' is-active' : ''}`}
                        onClick={() => onChange(tab.value)}
                    >
                        {tab.icon && <i className={tab.icon} />}
                        <span>{tab.label}</span>
                        {tab.badge !== undefined && (
                            <span className="app-tab-badge">{tab.badge}</span>
                        )}
                    </button>
                );
            })}
        </nav>
    );
}
