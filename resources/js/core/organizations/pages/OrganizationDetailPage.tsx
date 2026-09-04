import { useParams, useSearchParams } from 'react-router-dom';
import { Card } from '@/shared/components/ui/Card';
import { LoadingBlock, ErrorState } from '@/shared/components/ui/Feedback';
import { StatTiles } from '@/shared/components/ui/StatTiles';
import { Tabs } from '@/shared/components/ui/Tabs';
import { formatDate, formatDateTime, formatBytes, orDash } from '@/shared/utils/format';
import { cn } from '@/shared/utils/cn';
import { ModulesTab } from '../components/ModulesTab';
import { OrganizationHero } from '../components/OrganizationHero';
import { OrganizationHistoryTab } from '../components/OrganizationHistoryTab';
import { organizationsHooks, useOrganizationModules } from '../api';
import type { Organization, TenantDatabase, TenantMigrationState } from '../types';

/**
 * The seven tabs of Build Spec §17, in the order the spec gives them.
 *
 * Overview, Modules and Database & Health have content today. The rest are
 * declared now so the shape of the screen is settled before the data behind
 * each one lands.
 */
type TabKey = 'overview' | 'subscription' | 'database' | 'users' | 'usage' | 'audit' | 'danger';

interface Tab {
    key: TabKey;
    label: string;
    icon: string;
    /** Which build step fills this tab in; absent once it has content. */
    step?: string;
}

const TABS: Tab[] = [
    { key: 'overview', label: 'Overview', icon: 'ti ti-info-circle' },
    { key: 'subscription', label: 'Modules', icon: 'ti ti-puzzle' },
    { key: 'database', label: 'Database & Health', icon: 'ti ti-database' },
    { key: 'users', label: 'Users', icon: 'ti ti-users', step: 'step 4' },
    { key: 'usage', label: 'Usage', icon: 'ti ti-chart-bar', step: 'step 7' },
    { key: 'audit', label: 'History', icon: 'ti ti-history' },
    { key: 'danger', label: 'Danger Zone', icon: 'ti ti-alert-triangle', step: 'step 5' },
];

export default function OrganizationDetailPage() {
    const { uuid } = useParams();

    /*
     * The tab rides the query string so it can be linked to — the Modules
     * screen sends an administrator straight here to assign one — and so a
     * refresh and the back button both land where they were. The pathname
     * does not change, so switching tabs raises no navigation loader.
     */
    const [searchParams, setSearchParams] = useSearchParams();

    const requested = searchParams.get('tab') as TabKey | null;
    const tab: TabKey =
        requested && TABS.some((entry) => entry.key === requested) ? requested : 'overview';

    const setTab = (next: TabKey) =>
        setSearchParams(next === 'overview' ? {} : { tab: next }, { replace: true });

    const { data: organization, isLoading, isError, refetch } = organizationsHooks.useDetail(uuid);

    if (isLoading) {
        return <LoadingBlock label="Loading organization…" />;
    }

    if (isError || !organization) {
        return <ErrorState onRetry={() => refetch()} />;
    }

    const active = TABS.find((entry) => entry.key === tab);

    return (
        <>
            <OrganizationHero organization={organization} />

            <OrganizationVitals organization={organization} />

            <Tabs<TabKey>
                label="Organization sections"
                value={tab}
                onChange={setTab}
                tabs={TABS.map((entry) => ({
                    value: entry.key,
                    label: entry.label,
                    icon: entry.icon,
                }))}
            />

            {tab === 'overview' && <OverviewTab organization={organization} />}
            {tab === 'database' && <DatabaseHealthTab organization={organization} />}
            {tab === 'subscription' && <ModulesTab uuid={organization.uuid} />}
            {tab === 'audit' && <OrganizationHistoryTab uuid={organization.uuid} />}

            {!['overview', 'database', 'subscription', 'audit'].includes(tab) && (
                <ComingSoon label={active?.label ?? ''} step={active?.step} />
            )}
        </>
    );
}

/**
 * The four things worth knowing before opening any tab.
 *
 * Chosen because each one is a question somebody actually arrives with: is
 * it live, what has it been sold, is its database healthy, and has the owner
 * ever signed in. Everything else is detail and belongs under a tab.
 */
function OrganizationVitals({ organization }: { organization: Organization }) {
    const { data: modules, isLoading } = useOrganizationModules(organization.uuid);

    const database = organization.tenant_database;
    const optional = (modules?.modules ?? []).filter((module) => !module.is_core);
    const live = optional.filter((module) => module.is_live).length;

    return (
        <StatTiles
            loading={isLoading}
            tiles={[
                {
                    label: 'Modules',
                    value: live,
                    icon: 'ti ti-puzzle',
                    tone: 'indigo',
                    hint: `${modules?.capabilities.length ?? 0} permissions it can grant`,
                },
                {
                    label: 'Database',
                    // Size is the honest headline: health is a pill below it,
                    // and a size of zero means the tenant was never built.
                    value: database?.size_bytes != null ? formatBytes(database.size_bytes) : '—',
                    icon: 'ti ti-database',
                    tone: 'sky',
                    hint: database?.status
                        ? `${database.status.replace(/_/g, ' ')} · ${database.db_name}`
                        : 'Not provisioned',
                },
                {
                    label: 'Live since',
                    value: organization.activated_at
                        ? formatDate(organization.activated_at)
                        : 'Not yet',
                    icon: 'ti ti-calendar-check',
                    tone: 'emerald',
                    hint: `Created ${formatDate(organization.created_at)}`,
                },
                {
                    label: 'Owner access',
                    value: organization.is_setup_completed ? 'Set up' : 'Pending',
                    icon: organization.is_setup_completed ? 'ti ti-lock-open' : 'ti ti-lock',
                    tone: organization.is_setup_completed ? 'emerald' : 'amber',
                    hint: organization.is_setup_completed
                        ? 'The owner has set their password'
                        : 'The owner has not set a password yet',
                },
            ]}
        />
    );
}

function OverviewTab({ organization }: { organization: Organization }) {
    return (
        <div className="row g-3">
            <div className="col-lg-6">
                <Card className="od-card" title="Business" icon="ti ti-briefcase">
                    <DetailList
                        rows={[
                            ['Legal name', organization.legal_name],
                            ['GSTIN', organization.gstin],
                            ['Drug licence no.', organization.drug_license_no],
                            ['Type', organization.organization_type?.name ?? null],
                            ['Slug', organization.slug],
                        ]}
                    />
                </Card>
            </div>

            <div className="col-lg-6">
                <Card className="od-card" title="Contact" icon="ti ti-address-book">
                    <DetailList
                        rows={[
                            ['Contact person', organization.contact_person_name],
                            ['Email', organization.email],
                            ['Phone', organization.phone_number],
                            ['Address', organization.address],
                        ]}
                    />
                </Card>
            </div>

            <div className="col-lg-6">
                <Card className="od-card" title="Lifecycle" icon="ti ti-timeline">
                    {/*
                     * A timeline rather than four date rows. These events
                     * happen in an order, and a list of dates makes the
                     * reader reconstruct it every time.
                     */}
                    <Timeline organization={organization} />
                </Card>
            </div>

            <div className="col-lg-6">
                <Card className="od-card" title="Regional" icon="ti ti-world">
                    <DetailList
                        rows={[
                            ['Timezone', organization.timezone],
                            ['Currency', organization.currency],
                            ['Country', organization.country],
                        ]}
                    />
                </Card>
            </div>

            <div className="col-12">
                <Card className="od-card" title="Internal notes" icon="ti ti-note">
                    <p className="od-notes">{organization.notes || 'Nothing recorded yet.'}</p>

                    <small className="text-muted">
                        <i className="ti ti-lock me-1" />
                        Never shown to the organization.
                    </small>
                </Card>
            </div>
        </div>
    );
}

/** The events that have actually happened, oldest first. */
function Timeline({ organization }: { organization: Organization }) {
    const events = [
        {
            label: 'Created',
            at: organization.created_at,
            icon: 'ti ti-plus',
            tone: 'muted' as const,
        },
        {
            label: 'Activated',
            at: organization.activated_at,
            icon: 'ti ti-check',
            tone: 'emerald' as const,
        },
        {
            label: organization.suspension_reason
                ? `Suspended — ${organization.suspension_reason}`
                : 'Suspended',
            at: organization.suspended_at,
            icon: 'ti ti-ban',
            tone: 'rose' as const,
        },
    ].filter((event) => event.at);

    if (events.length === 0) {
        return <p className="text-muted mb-0">Nothing has happened to this organization yet.</p>;
    }

    return (
        <ol className="od-timeline">
            {events.map((event) => (
                <li key={event.label} className={`is-${event.tone}`}>
                    <span className="od-timeline-dot" aria-hidden="true">
                        <i className={event.icon} />
                    </span>

                    <span className="od-timeline-body">
                        <b>{event.label}</b>
                        <span>{formatDateTime(event.at)}</span>
                    </span>
                </li>
            ))}
        </ol>
    );
}

/** Colors reused from OrganizationStatusBadge's own vocabulary — no new CSS. */
const PILL_VARIANTS: Record<string, string> = {
    healthy: 'emerald',
    provisioned: 'emerald',
    in_sync: 'emerald',
    success: 'emerald',
    degraded: 'amber',
    behind: 'amber',
    provisioning: 'sky',
    pending: 'muted',
    unreachable: 'rose',
    failed: 'rose',
};

function Pill({ value }: { value: string | null }) {
    if (!value) {
        return <span className="text-muted">{orDash(null)}</span>;
    }

    const variant = PILL_VARIANTS[value] ?? 'muted';

    return (
        <span className={cn('org-status', `org-status--${variant}`)}>
            <span className="org-status-dot" />
            {value.replace(/_/g, ' ')}
        </span>
    );
}

function DatabaseHealthTab({ organization }: { organization: Organization }) {
    const database = organization.tenant_database;
    const migration = organization.migration_state;

    if (!database && !migration) {
        return (
            <Card>
                <div className="org-pending">
                    <i className="ti ti-database-off" />
                    <h6>Not provisioned yet</h6>
                    <p>This organization has no tenant database recorded.</p>
                </div>
            </Card>
        );
    }

    return (
        <div className="row g-3">
            <div className="col-lg-6">
                <TenantDatabaseCard database={database} />
            </div>

            <div className="col-lg-6">
                <MigrationStateCard migration={migration} />
            </div>
        </div>
    );
}

function TenantDatabaseCard({ database }: { database: TenantDatabase | null | undefined }) {
    return (
        <Card className="od-card" title="Tenant Database" icon="ti ti-database">
            {!database ? (
                <p className="text-muted mb-0">No database record yet.</p>
            ) : (
                <dl className="org-details">
                    <div>
                        <dt>Database name</dt>
                        <dd>
                            <code>{database.db_name}</code>
                        </dd>
                    </div>
                    <div>
                        <dt>Cluster</dt>
                        <dd>{orDash(database.db_cluster)}</dd>
                    </div>
                    <div>
                        <dt>Provision status</dt>
                        <dd>
                            <Pill value={database.provision_status} />
                        </dd>
                    </div>
                    <div>
                        <dt>Health</dt>
                        <dd>
                            <Pill value={database.status} />
                        </dd>
                    </div>
                    <div>
                        <dt>Size</dt>
                        <dd>
                            {database.size_bytes !== null
                                ? formatBytes(database.size_bytes)
                                : orDash(null)}
                        </dd>
                    </div>
                    <div>
                        <dt>Last backup</dt>
                        <dd>{formatDateTime(database.last_backup_at)}</dd>
                    </div>
                    <div>
                        <dt>Last backup status</dt>
                        <dd>
                            <Pill value={database.last_backup_status} />
                        </dd>
                    </div>
                </dl>
            )}
        </Card>
    );
}

function MigrationStateCard({ migration }: { migration: TenantMigrationState | null | undefined }) {
    return (
        <Card className="od-card" title="Schema & Migrations" icon="ti ti-git-branch">
            {!migration ? (
                <p className="text-muted mb-0">No migration record yet.</p>
            ) : (
                <dl className="org-details">
                    <div>
                        <dt>Status</dt>
                        <dd>
                            <Pill value={migration.status} />
                        </dd>
                    </div>
                    <div>
                        <dt>Current version</dt>
                        <dd>{orDash(migration.current_version)}</dd>
                    </div>
                    <div>
                        <dt>Target version</dt>
                        <dd>{orDash(migration.target_version)}</dd>
                    </div>
                    <div>
                        <dt>Attempts</dt>
                        <dd>{migration.attempts}</dd>
                    </div>
                    <div>
                        <dt>Last run</dt>
                        <dd>{formatDateTime(migration.last_run_at)}</dd>
                    </div>
                    {migration.last_error && (
                        <div>
                            <dt>Last error</dt>
                            <dd className="text-danger">{migration.last_error}</dd>
                        </div>
                    )}
                </dl>
            )}
        </Card>
    );
}

function DetailList({ rows }: { rows: [string, string | null | undefined][] }) {
    return (
        <dl className="org-details">
            {rows.map(([label, value]) => (
                <div key={label}>
                    <dt>{label}</dt>
                    <dd>{orDash(value)}</dd>
                </div>
            ))}
        </dl>
    );
}

/**
 * Says which step fills the tab in, rather than pretending it is broken.
 */
function ComingSoon({ label, step }: { label: string; step?: string }) {
    return (
        <Card>
            <div className="org-pending">
                <i className="ti ti-tools" />
                <h6>{label}</h6>
                <p>
                    Not built yet{step ? ` — this arrives in ${step}` : ''}. The tab is here so the
                    screen&rsquo;s shape is settled before the data behind it lands.
                </p>
            </div>
        </Card>
    );
}
