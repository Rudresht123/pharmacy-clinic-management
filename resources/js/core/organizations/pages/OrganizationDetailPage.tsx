import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import { LoadingBlock, ErrorState } from '@/shared/components/ui/Feedback';
import { Avatar } from '@/shared/components/ui/Avatar';
import { formatDate, formatDateTime, formatBytes, orDash } from '@/shared/utils/format';
import { cn } from '@/shared/utils/cn';
import { notify } from '@/shared/utils/notify';
import { OrganizationStatusBadge } from '../components/OrganizationStatusBadge';
import { organizationsHooks, retryOrganizationProvisioning } from '../api';
import type { Organization, TenantDatabase, TenantMigrationState } from '../types';

/**
 * The seven tabs of Build Spec §17, in the order the spec gives them.
 *
 * Overview and Database & Health have content today. The rest are declared
 * now so the shape of the screen is settled before the data behind each one
 * lands — Subscription in step 6, Users in step 4, Audit in step 3.
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
    { key: 'subscription', label: 'Subscription & Modules', icon: 'ti ti-package', step: 'step 6' },
    { key: 'database', label: 'Database & Health', icon: 'ti ti-database' },
    { key: 'users', label: 'Users', icon: 'ti ti-users', step: 'step 4' },
    { key: 'usage', label: 'Usage', icon: 'ti ti-chart-bar', step: 'step 7' },
    { key: 'audit', label: 'Audit', icon: 'ti ti-history', step: 'step 3' },
    { key: 'danger', label: 'Danger Zone', icon: 'ti ti-alert-triangle', step: 'step 5' },
];

export default function OrganizationDetailPage() {
    const { uuid } = useParams();
    const [tab, setTab] = useState<TabKey>('overview');

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
            <PageHeader
                title={organization.organization_name}
                icon="ti ti-building-store"
                tone="indigo"
                crumbs={[
                    { label: 'Global Settings' },
                    { label: 'Organizations', to: '/organizations' },
                    { label: organization.organization_name },
                ]}
                actions={
                    <Link
                        to={`/organizations/${organization.uuid}/edit`}
                        className="btn btn-primary"
                    >
                        <i className="ti ti-edit me-1" />
                        Edit
                    </Link>
                }
            />

            <IdentityCard organization={organization} />

            <nav className="org-tabs" role="tablist" aria-label="Organization sections">
                {TABS.map((entry) => (
                    <button
                        key={entry.key}
                        type="button"
                        role="tab"
                        aria-selected={tab === entry.key}
                        className={cn('org-tab', tab === entry.key && 'is-active')}
                        onClick={() => setTab(entry.key)}
                    >
                        <i className={entry.icon} />
                        <span>{entry.label}</span>
                    </button>
                ))}
            </nav>

            {tab === 'overview' && <OverviewTab organization={organization} />}
            {tab === 'database' && <DatabaseHealthTab organization={organization} />}
            {tab !== 'overview' && tab !== 'database' && (
                <ComingSoon label={active?.label ?? ''} step={active?.step} />
            )}
        </>
    );
}

/** The summary strip that stays put whichever tab is open. */
function IdentityCard({ organization }: { organization: Organization }) {
    const queryClient = useQueryClient();

    const retry = useMutation({
        mutationFn: () => retryOrganizationProvisioning(organization.uuid),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: organizationsHooks.keys.all });
            queryClient.invalidateQueries({
                queryKey: organizationsHooks.keys.detail(organization.uuid),
            });
            notify.success('Provisioning retried');
        },
    });

    return (
        <div className="org-identity">
            <Avatar
                name={organization.organization_name}
                src={organization.profile_image_url}
                hasImage={organization.profile_image_id !== null}
                size={64}
                preview
            />

            <div className="org-identity-main">
                <div className="org-identity-title">
                    <h5>{organization.organization_name}</h5>
                    <OrganizationStatusBadge status={organization.status} />

                    {organization.status === 'failed' && (
                        <Button
                            variant="light"
                            size="sm"
                            icon="ti ti-refresh"
                            loading={retry.isPending}
                            onClick={() => retry.mutate()}
                        >
                            Retry Provisioning
                        </Button>
                    )}
                </div>

                <div className="org-identity-meta">
                    <span>
                        <i className="ti ti-hash" />
                        {organization.organization_code}
                    </span>
                    <span>
                        <i className="ti ti-world" />
                        {organization.subdomain}
                    </span>
                    <span>
                        <i className="ti ti-mail" />
                        {orDash(organization.email)}
                    </span>
                </div>
            </div>
        </div>
    );
}

function OverviewTab({ organization }: { organization: Organization }) {
    return (
        <div className="row g-3">
            <div className="col-lg-6">
                <Card title="Business">
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
                <Card title="Contact">
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
                <Card title="Lifecycle">
                    <DetailList
                        rows={[
                            ['Created', formatDate(organization.created_at)],
                            ['Activated', formatDate(organization.activated_at)],
                            ['Suspended', formatDate(organization.suspended_at)],
                            ['Suspension reason', organization.suspension_reason],
                            [
                                'Owner set their password',
                                organization.is_setup_completed ? 'Yes' : 'Not yet',
                            ],
                        ]}
                    />
                </Card>
            </div>

            <div className="col-lg-6">
                <Card title="Regional">
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
                <Card title="Internal notes">
                    <p className="mb-1">{organization.notes || 'Nothing recorded yet.'}</p>
                    <small className="text-muted">
                        <i className="ti ti-lock me-1" />
                        Never shown to the organization.
                    </small>
                </Card>
            </div>
        </div>
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
        <Card title="Tenant Database">
            {!database ? (
                <p className="text-muted mb-0">No database record yet.</p>
            ) : (
                <dl className="org-details">
                    <div>
                        <dt>Database name</dt>
                        <dd>{database.db_name}</dd>
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
                            {database.size_bytes !== null ? formatBytes(database.size_bytes) : orDash(null)}
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
        <Card title="Schema & Migrations">
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
