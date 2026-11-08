import { useSearchParams } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { ErrorState, LoadingBlock } from '@/shared/components/ui/Feedback';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { cn } from '@/shared/utils/cn';
import { CATALOGUE_TICKS } from '../../tests/catalogue';
import { TestCataloguePanel } from '../../tests/pages/LabTestListPage';
import { useLabMasterCatalogue } from '../api';
import { GeneralPanel } from '../components/GeneralPanel';
import { MasterPanel } from '../components/MasterPanel';

const GENERAL = 'general';
const TESTS = 'tests';

/**
 * Settings › Laboratory: how the lab works, the tests it offers, and the short
 * lists every lab screen picks from.
 *
 * One page, a menu down the side: General, the Test Catalogue, then each list.
 * The catalogue is a tab here rather than a Settings row of its own (the
 * user's ask, 2026-11-08). The lists are described by the server, so this page
 * names none of them itself. What is open lives in `?section=`, so a link can
 * land on one tab.
 *
 * Each part shows to whoever holds its ticks: General and the lists to lab
 * setup, the catalogue to the catalogue ticks. Only `laboratory.settings_manage`
 * changes settings and lists; only `laboratory.catalog_manage` changes tests.
 */
export default function LabSettingsPage() {
    const { can } = useTenantAuth();
    const editable = can('laboratory.settings_manage');
    const setup = can('laboratory.settings_view') || editable;
    const tests = CATALOGUE_TICKS.some(can);

    const { data: catalogue, isLoading, isError, refetch } = useLabMasterCatalogue();
    const [params, setParams] = useSearchParams();

    // Whoever holds only the catalogue ticks lands on it.
    const home = setup ? GENERAL : TESTS;
    const requested = params.get('section') ?? home;
    const definition = setup ? catalogue?.find((list) => list.key === requested) : undefined;
    const section = definition ? requested : requested === TESTS && tests ? TESTS : home;

    const open = (next: string) => setParams(next === home ? {} : { section: next }, { replace: true });

    const item = (key: string, icon: string, label: string) => (
        <button
            key={key}
            type="button"
            className={cn('lab-nav-item', section === key && 'is-active')}
            aria-current={section === key ? 'page' : undefined}
            onClick={() => open(key)}
        >
            <i className={icon} aria-hidden="true" />
            <span>{label}</span>
        </button>
    );

    return (
        <>
            <PageHeader
                title="Laboratory Settings"
                subtitle="How results are approved, the tests the lab offers, and the lists every lab screen picks from."
                icon="ti ti-flask"
                tone="violet"
                crumbs={[{ label: 'Settings' }, { label: 'Laboratory' }]}
            />

            {isLoading ? (
                <LoadingBlock label="Loading laboratory settings…" />
            ) : isError || !catalogue ? (
                <ErrorState onRetry={refetch} />
            ) : (
                <div className="lab-settings">
                    <nav className="lab-nav" aria-label="Laboratory settings">
                        {setup && (
                            <>
                                <p className="lab-nav-heading">Workflow</p>
                                {item(GENERAL, 'ti ti-adjustments', 'General')}
                            </>
                        )}

                        {tests && (
                            <>
                                <p className="lab-nav-heading">Catalogue</p>
                                {item(TESTS, 'ti ti-test-pipe', 'Test Catalogue')}
                            </>
                        )}

                        {setup && (
                            <>
                                <p className="lab-nav-heading">Lists</p>
                                {catalogue.map((list) => item(list.key, list.icon, list.plural))}
                            </>
                        )}
                    </nav>

                    <div className="lab-settings-body">
                        {section === TESTS ? (
                            <TestCataloguePanel />
                        ) : definition ? (
                            <MasterPanel key={definition.key} definition={definition} editable={editable} />
                        ) : (
                            <GeneralPanel editable={editable} />
                        )}
                    </div>
                </div>
            )}
        </>
    );
}
