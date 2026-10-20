import { Link, useSearchParams } from 'react-router-dom';
import { EmptyState, ErrorState } from '@/shared/components/ui/Feedback';
import { cn } from '@/shared/utils/cn';
import { useExportCatalogue } from '../api';
import { ExportWorkspace } from '../components/ExportWorkspace';
import type { TabKey } from '../types';

/**
 * The Export Center — every record this person may take away, in one place.
 *
 * Four tabs across the top (Patients, Pharmacy, Billing, Communication), only
 * the ones a role gives; the tab's records down the side; and for the one
 * chosen, its filters and columns, then the records themselves with the three
 * formats. Which branches each tab reaches and which columns each export
 * carries are the server's answer — this screen draws what it was given.
 *
 * The tab and export ride the query string (?tab=billing&export=billing.payments)
 * so a refresh, the back button and a shared link land on the same export.
 */
export default function ExportCenterPage() {
    const [params, setParams] = useSearchParams();
    const catalogue = useExportCatalogue();

    const tabs = catalogue.data?.tabs ?? [];
    const tab = tabs.find((entry) => entry.key === params.get('tab')) ?? tabs[0];
    const dataset = tab?.datasets.find((entry) => entry.key === params.get('export')) ?? tab?.datasets[0];

    const go = (next: { tab?: TabKey; export?: string }) => {
        const updated = new URLSearchParams(params);

        if (next.tab) {
            updated.set('tab', next.tab);
            updated.delete('export');
        }

        if (next.export) updated.set('export', next.export);

        setParams(updated, { replace: true });
    };

    return (
        <div className="exc-page">
            <h1 className="visually-hidden">Export Center</h1>

            {catalogue.isLoading ? (
                <div className="exc-layout">
                    <div className="exc-card exc-skeleton" />
                    <div className="exc-card exc-skeleton is-tall" />
                </div>
            ) : catalogue.isError ? (
                <section className="exc-card">
                    <ErrorState onRetry={() => catalogue.refetch()} />
                </section>
            ) : !tab || !dataset ? (
                <section className="exc-card">
                    <EmptyState
                        icon="ti ti-file-off"
                        title="Nothing to export yet"
                        description="Your role does not include any export. An owner can give one on the Roles & Permissions screen."
                    />
                </section>
            ) : (
                <>
                    <div className="exc-topbar">
                        <nav className="exc-tabs" role="tablist" aria-label="Export areas">
                            {tabs.map((entry) => {
                                const on = entry.key === tab.key;

                                return (
                                    <button
                                        key={entry.key}
                                        type="button"
                                        role="tab"
                                        aria-selected={on}
                                        className={cn('exc-tab', on && 'is-on')}
                                        onClick={() => go({ tab: entry.key })}
                                    >
                                        <i className={entry.icon} aria-hidden="true" />
                                        {entry.label}
                                        <span className="exc-tab-count">{entry.datasets.length}</span>
                                    </button>
                                );
                            })}
                        </nav>

                        {catalogue.data?.can_manage_columns && (
                            <Link to="/exports/columns" className="btn exc-btn-quiet">
                                <i className="ti ti-columns-3" aria-hidden="true" /> Column access
                            </Link>
                        )}
                    </div>

                    <div className="exc-layout">
                        <nav className="exc-card exc-sets" aria-label={`${tab.label} records`}>
                            <p className="exc-eyebrow">Records to export</p>

                            {tab.datasets.map((entry) => {
                                const on = entry.key === dataset.key;

                                return (
                                    <button
                                        key={entry.key}
                                        type="button"
                                        className={cn('exc-set', on && 'is-on')}
                                        aria-current={on ? 'page' : undefined}
                                        onClick={() => go({ export: entry.key })}
                                    >
                                        <span className="exc-set-icon" aria-hidden="true">
                                            <i className={entry.icon} />
                                        </span>
                                        <span className="exc-set-text">
                                            <b>{entry.label}</b>
                                            <small>{entry.columns.length} columns</small>
                                        </span>
                                        <i className="ti ti-chevron-right exc-set-go" aria-hidden="true" />
                                    </button>
                                );
                            })}
                        </nav>

                        {/*
                            Its two cards are this grid's own children: the
                            filter card beside the list, the same height as it;
                            the records under both, the full width.
                        */}
                        <ExportWorkspace
                            key={dataset.key}
                            tab={tab}
                            dataset={dataset}
                            doctors={catalogue.data?.doctors ?? []}
                            pdfLimit={catalogue.data?.pdf_limit ?? 25000}
                        />
                    </div>
                </>
            )}
        </div>
    );
}
