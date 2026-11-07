import { useSearchParams } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { ErrorState, LoadingBlock } from '@/shared/components/ui/Feedback';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { cn } from '@/shared/utils/cn';
import { useLabMasterCatalogue } from '../api';
import { GeneralPanel } from '../components/GeneralPanel';
import { MasterPanel } from '../components/MasterPanel';

const GENERAL = 'general';

/**
 * Settings › Laboratory: how the lab works, and the short lists every lab
 * screen picks from.
 *
 * One page, a menu down the side: General first, then each list. The lists are
 * described by the server, so this page names none of them itself. What is
 * open lives in `?section=`, so a link can land on one list.
 *
 * Everyone with the screen's tick reads it; only lab setup
 * (`laboratory.settings_manage`) can change anything.
 */
export default function LabSettingsPage() {
    const { can } = useTenantAuth();
    const editable = can('laboratory.settings_manage');

    const { data: catalogue, isLoading, isError, refetch } = useLabMasterCatalogue();
    const [params, setParams] = useSearchParams();

    const requested = params.get('section') ?? GENERAL;
    const definition = catalogue?.find((list) => list.key === requested);
    const section = definition ? requested : GENERAL;

    const open = (next: string) =>
        setParams(next === GENERAL ? {} : { section: next }, { replace: true });

    return (
        <>
            <PageHeader
                title="Laboratory Settings"
                subtitle="How results are approved, and the lists every lab screen picks from."
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
                        <p className="lab-nav-heading">Workflow</p>
                        <button
                            type="button"
                            className={cn('lab-nav-item', section === GENERAL && 'is-active')}
                            aria-current={section === GENERAL ? 'page' : undefined}
                            onClick={() => open(GENERAL)}
                        >
                            <i className="ti ti-adjustments" aria-hidden="true" />
                            <span>General</span>
                        </button>

                        <p className="lab-nav-heading">Lists</p>
                        {catalogue.map((list) => (
                            <button
                                key={list.key}
                                type="button"
                                className={cn('lab-nav-item', section === list.key && 'is-active')}
                                aria-current={section === list.key ? 'page' : undefined}
                                onClick={() => open(list.key)}
                            >
                                <i className={list.icon} aria-hidden="true" />
                                <span>{list.plural}</span>
                            </button>
                        ))}
                    </nav>

                    <div className="lab-settings-body">
                        {definition ? (
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
