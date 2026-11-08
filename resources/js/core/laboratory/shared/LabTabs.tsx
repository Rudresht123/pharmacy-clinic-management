/**
 * The lab's tab strip, drawn exactly as Billing draws its own (the user's ask,
 * 2026-11-08): a grey track on the page, outside the card, the chosen tab in
 * the brand colour with its count in a pill, the others with "(count)".
 *
 * It reuses Billing's `.inv-tabs` classes on purpose, so the two modules can
 * never drift apart.
 */
export function LabTabs<T extends string>({
    tabs,
    value,
    onChange,
    label,
}: {
    tabs: readonly { value: T; label: string; count?: number }[];
    value: T;
    onChange: (next: T) => void;
    label: string;
}) {
    return (
        <div className="inv-tabs" role="tablist" aria-label={label}>
            {tabs.map((tab) => {
                const active = tab.value === value;

                return (
                    <button
                        key={tab.value}
                        type="button"
                        role="tab"
                        aria-selected={active}
                        className={`inv-tab${active ? ' is-active' : ''}`}
                        onClick={() => onChange(tab.value)}
                    >
                        {tab.label}
                        {tab.count !== undefined &&
                            (active ? (
                                <span className="inv-tab-count">{tab.count.toLocaleString('en-IN')}</span>
                            ) : (
                                <span className="inv-tab-paren">({tab.count.toLocaleString('en-IN')})</span>
                            ))}
                    </button>
                );
            })}
        </div>
    );
}
