import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { LoadingBlock, ErrorState } from '@/shared/components/ui/Feedback';
import { Avatar } from '@/shared/components/ui/Avatar';
import { formatDate, orDash } from '@/shared/utils/format';
import { cn } from '@/shared/utils/cn';
import { OrganizationStatusBadge } from '../components/OrganizationStatusBadge';
import { organizationsHooks } from '../api';
import type { Organization } from '../types';

/**
 * The seven tabs of Build Spec §17, in the order the spec gives them.
 *
 * Only Overview has content today. The rest are declared now so the shape of
 * the screen is settled before the data behind each one lands — Subscription
 * in step 6, Database & Health in step 4, Audit in step 3.
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
    { key: 'database', label: 'Database & Health', icon: 'ti ti-database', step: 'step 4' },
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

            {tab === 'overview' ? (
                <OverviewTab organization={organization} />
            ) : (
                <ComingSoon label={active?.label ?? ''} step={active?.step} />
            )}
        </>
    );
}

/** The summary strip that stays put whichever tab is open. */
function IdentityCard({ organization }: { organization: Organization }) {
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
