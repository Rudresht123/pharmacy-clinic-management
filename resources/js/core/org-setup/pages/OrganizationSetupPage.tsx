import { useEffect, useState, type ComponentType } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { ErrorState, LoadingBlock } from '@/shared/components/ui/Feedback';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { useSetupStatus } from '../api';
import { SetupMenu } from '../components/SetupMenu';
import type { SectionProps } from '../components/SectionShell';
import { STEP_META, type SetupStatus, type SetupStepKey } from '../types';
import { OrganizationSection } from '../sections/OrganizationSection';
import { BranchesSection } from '../sections/BranchesSection';
import { DepartmentsSection } from '../sections/DepartmentsSection';
import { StaffSection } from '../sections/StaffSection';
import { RolesSection } from '../sections/RolesSection';
import { SettingsSection } from '../sections/SettingsSection';
import { ReviewSection } from '../sections/ReviewSection';

const SECTIONS: Record<SetupStepKey, ComponentType<SectionProps>> = {
    organization: OrganizationSection,
    branches: BranchesSection,
    departments: DepartmentsSection,
    users: StaffSection,
    roles: RolesSection,
    settings: SettingsSection,
    review: ReviewSection,
};

const LEAVE = 'You have unsaved changes in this step. Leave it without saving them?';

/** "12 Dec 2024, 10:30 AM" */
function stamp(value: string): string {
    return new Date(value).toLocaleString(undefined, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}

/** A line of encouragement that says something true about where they are. */
function encouragement(data: SetupStatus): { title: string; line: string } {
    if (data.completed_at) {
        return { title: 'All set!', line: 'Your organisation is ready. Change anything here whenever you need to.' };
    }

    if (data.can_complete) {
        return { title: 'Almost there!', line: 'Everything required is done — complete the setup from Final Review.' };
    }

    if (data.completed_count === 0) {
        return { title: 'Let’s get started', line: 'Begin with your organisation’s basic details.' };
    }

    return { title: 'You’re doing great!', line: 'Complete the remaining steps to unlock your workspace.' };
}

/**
 * Setting the organisation up — a page of its own.
 *
 * Not inside the workspace: nothing else opens until setup is finished, so
 * the app's sidebar and search would only offer doors that are shut. A header
 * and the overall progress across the top, the steps on the left, the open
 * step on the right.
 *
 * Which step is open lives in the URL (?section=), so a refresh or the back
 * button lands in the same place and switching never reloads the page. With
 * none named it opens on the first required step still to do. Progress is
 * the server's, read from the organisation's own data, so it is the same
 * after signing out and in.
 */
export default function OrganizationSetupPage() {
    const { data, isLoading, isError, refetch } = useSetupStatus();
    const { logout } = useTenantAuth();
    const [params, setParams] = useSearchParams();

    const [dirty, setDirty] = useState(false);
    const [stepsOpen, setStepsOpen] = useState(false);

    // A browser tab closed mid-sentence asks first.
    useEffect(() => {
        if (!dirty) return;

        function warn(event: BeforeUnloadEvent) {
            event.preventDefault();
            event.returnValue = '';
        }

        window.addEventListener('beforeunload', warn);

        return () => window.removeEventListener('beforeunload', warn);
    }, [dirty]);

    if (isLoading) {
        return (
            <div className="ob-loading">
                <LoadingBlock label="Getting your setup ready…" />
            </div>
        );
    }

    if (isError || !data) {
        return (
            <div className="ob-loading">
                <ErrorState onRetry={() => refetch()} />
            </div>
        );
    }

    const keys = data.steps.map((step) => step.key);
    const asked = params.get('section') as SetupStepKey | null;
    const active: SetupStepKey = asked && keys.includes(asked) ? asked : data.current;
    const step = data.steps.find((entry) => entry.key === active) ?? data.steps[0];
    const index = keys.indexOf(active);
    const cheer = encouragement(data);

    function go(key: SetupStepKey, force = false) {
        setStepsOpen(false);

        if (key === active) return;

        if (dirty && !force && !window.confirm(LEAVE)) return;

        setDirty(false);
        setParams({ section: key });
        window.scrollTo({ top: 0 });

        // The new step's heading takes focus, so a screen reader hears where it landed.
        requestAnimationFrame(() => document.getElementById('su-section-title')?.focus());
    }

    function signOut() {
        if (dirty && !window.confirm(LEAVE)) return;

        void logout();
    }

    const Section = SECTIONS[active];

    return (
        <div className="ob">
            <div className="ob-wrap">
                <header className="ob-card ob-head">
                    <span className="ob-head-icon" aria-hidden="true">
                        <i className="ti ti-building-hospital" />
                    </span>

                    <div className="ob-head-text">
                        <h1>Organisation Setup</h1>
                        <p>
                            Let’s set up {data.organization.name} to get started. Complete the steps below to
                            configure your clinic.
                        </p>
                    </div>

                    <div className="ob-head-side">
                        <span className={`ob-status${data.completed_at ? ' is-done' : ''}`}>
                            <i className={data.completed_at ? 'ti ti-circle-check' : 'ti ti-clock'} aria-hidden="true" />
                            {data.completed_at ? 'Completed' : 'In Progress'}
                        </span>

                        {data.last_updated && <small>Last updated: {stamp(data.last_updated)}</small>}

                        <div className="ob-head-links">
                            {data.completed_at && <Link to="/dashboard">Dashboard</Link>}
                            <a href="mailto:support@hms.local">Help</a>
                            <button type="button" onClick={signOut}>
                                Sign out
                            </button>
                        </div>
                    </div>
                </header>

                <section className="ob-card ob-progress" aria-label="Setup progress">
                    <div className="ob-progress-label">
                        <b>Your Setup Progress</b>
                        <span>
                            {data.completed_count} of {data.total} steps completed
                        </span>
                    </div>

                    <div className="ob-progress-track">
                        <div
                            className="ob-bar"
                            role="progressbar"
                            aria-valuemin={0}
                            aria-valuemax={100}
                            aria-valuenow={data.percent}
                            aria-label="Setup progress"
                        >
                            <span style={{ width: `${data.percent}%` }} />
                        </div>
                        <b>{data.percent}%</b>
                    </div>

                    <div className="ob-progress-note">
                        <i className="ti ti-rocket" aria-hidden="true" />
                        <div>
                            <b>{cheer.title}</b>
                            <span>{cheer.line}</span>
                        </div>
                    </div>
                </section>

                <div className="ob-layout">
                    <aside className={`ob-card ob-side${stepsOpen ? ' is-open' : ''}`}>
                        {/* On a phone the steps fold away behind this. */}
                        <button
                            type="button"
                            className="ob-steps-toggle"
                            aria-expanded={stepsOpen}
                            aria-controls="su-menu"
                            onClick={() => setStepsOpen((was) => !was)}
                        >
                            <span>
                                <small>
                                    Step {index + 1} of {keys.length}
                                </small>
                                <b>{STEP_META[active].title}</b>
                            </span>
                            <i className={stepsOpen ? 'ti ti-chevron-up' : 'ti ti-chevron-down'} aria-hidden="true" />
                        </button>

                        <SetupMenu id="su-menu" steps={data.steps} active={active} onSelect={(key) => go(key)} />
                    </aside>

                    <main className="ob-main">
                        {/* Keyed, so each step starts from the server's copy. */}
                        <Section
                            key={active}
                            status={data}
                            step={step}
                            onDirty={setDirty}
                            nav={{
                                prev: index > 0 ? keys[index - 1] : null,
                                next: index < keys.length - 1 ? keys[index + 1] : null,
                                index,
                                total: keys.length,
                                go,
                            }}
                        />
                    </main>
                </div>
            </div>
        </div>
    );
}
