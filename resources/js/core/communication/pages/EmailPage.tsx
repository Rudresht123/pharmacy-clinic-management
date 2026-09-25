import { useEffect, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Button } from '@/shared/components/ui/Button';
import { Card } from '@/shared/components/ui/Card';
import { Tabs, type TabItem } from '@/shared/components/ui/Tabs';
import { ErrorState, LoadingBlock } from '@/shared/components/ui/Feedback';
import type { StatTile } from '@/shared/components/ui/StatTiles';
import { resolveErrorMessage } from '@/shared/api/http';
import { ChannelConnection } from '../components/ChannelConnection';
import { SetupProgress } from '../components/SetupProgress';
import { ChannelBenefits, EMAIL_BENEFITS } from '../components/ChannelBenefits';
import { ChannelStats } from '../components/ChannelStats';
import { TemplateTable } from '../components/TemplateTable';
import { EmailPreview } from '../components/EmailPreview';
import { AutomationRules } from '../components/AutomationRules';
import { RecentMessages } from '../components/RecentMessages';
import { ComplianceNote } from '../components/ComplianceNote';
import { TestSendModal } from '../components/TestSendModal';
import { TemplateFormModal } from '../components/TemplateFormModal';
import { MessageDetailModal } from '../components/MessageDetailModal';
import { AutomationRuleModal } from '../components/AutomationRuleModal';
import { ConnectionModal } from '../components/ConnectionModal';
import { ChannelAnalyticsPanel } from '../components/ChannelAnalyticsPanel';
import { CampaignsTable } from '../components/CampaignsTable';

import { ReasonDialog } from '@/core/medicines/components/ReasonDialog';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import {
    useCampaigns,
    useRemoveCampaign,
    useSendCampaign,
    useUnscheduleCampaign,
    useChannelOverview,
    useRemoveTemplate,
    useSaveTemplate,
    useSaveConnection,
    useSaveRule,
    useSendTest,
    useToggleRule,
} from '../api';
import type {
    AutomationRule,
    ChannelFigures,
    DeliveryRecord,
    MessageTemplate,
} from '../types';

const CATEGORIES = ['All', 'Appointment', 'Payments', 'Lab', 'Pharmacy', 'Follow-up', 'General'] as const;

type View = 'overview' | 'campaigns' | 'templates' | 'automation' | 'analytics' | 'settings';

const VIEWS: readonly TabItem<View>[] = [
    { value: 'overview', label: 'Overview', icon: 'ti ti-layout-dashboard' },
    { value: 'campaigns', label: 'Campaigns', icon: 'ti ti-send' },
    { value: 'templates', label: 'Templates', icon: 'ti ti-template' },
    { value: 'automation', label: 'Automation', icon: 'ti ti-settings-automation' },
    { value: 'analytics', label: 'Analytics', icon: 'ti ti-chart-bar' },
    { value: 'settings', label: 'Settings', icon: 'ti ti-adjustments' },
];

function count(value: number): string {
    return value.toLocaleString('en-IN');
}

/** Email calls it "opened" where WhatsApp calls it "read"; same fact. */
function tiles(figures: ChannelFigures): StatTile[] {
    const change =
        figures.change === null
            ? `Last ${figures.days} days`
            : `${figures.change >= 0 ? 'up' : 'down'} ${Math.abs(figures.change)}% on the previous ${figures.days} days`;

    return [
        { label: 'Emails sent', value: count(figures.sent), icon: 'ti ti-send', tone: 'sky', hint: change },
        {
            label: 'Delivered',
            value: count(figures.delivered),
            icon: 'ti ti-circle-check',
            tone: 'emerald',
            hint: figures.delivery_rate === null ? 'Nothing sent yet' : `${figures.delivery_rate}% delivery rate`,
        },
        {
            label: 'Opened',
            value: count(figures.read),
            icon: 'ti ti-eye',
            tone: 'teal',
            hint: figures.read_rate === null ? 'Nothing delivered yet' : `${figures.read_rate}% open rate`,
        },
        {
            label: 'Failed',
            value: count(figures.failed),
            icon: 'ti ti-circle-x',
            tone: figures.failed > 0 ? 'rose' : 'muted',
            hint: figures.failure_rate === null ? 'Nothing sent yet' : `${figures.failure_rate}% failed`,
        },
    ];
}

/**
 * Email, for a clinic.
 *
 * The same panels as the WhatsApp screen, arranged differently because email
 * is a different job: the library is searchable rather than chipped, and the
 * preview renders a page rather than a chat bubble.
 *
 * The connection and the period's figures stay above the tabs. They are the
 * channel's state rather than one view of it — "is this sending, and is it
 * landing" is a question somebody has on every tab.
 */
export default function EmailPage() {
    const navigate = useNavigate();
    const [searchParams, setSearchParams] = useSearchParams();

    /* In the URL rather than in state: the campaign wizard is its own page
       now, and coming back from it must land on Campaigns rather than dumping
       somebody on Overview to find their way again. */
    const view = (searchParams.get('view') as View | null) ?? 'overview';

    const setView = (next: View) =>
        setSearchParams(next === 'overview' ? {} : { view: next }, { replace: true });
    const [days, setDays] = useState(30);
    const [selected, setSelected] = useState<MessageTemplate | null>(null);
    const [testing, setTesting] = useState(false);

    /*
     * Reading is `communication.view`; every write on this screen is
     * `communication.manage`, which is where the API routes are split. Each
     * write affordance below is passed only when this holds — absent rather
     * than disabled, because a button that can only answer 403 is worse than
     * no button at all.
     */
    const { can } = useTenantAuth();
    const manage = can('communication.manage');

    const { data, isLoading, isError, error, refetch } = useChannelOverview('email', days);

    const toggleRule = useToggleRule();
    const sendTest = useSendTest('email');
    const saveTemplate = useSaveTemplate('email');
    const removeTemplate = useRemoveTemplate();
    const saveRule = useSaveRule();
    const saveConnection = useSaveConnection('email');

    // Null closed, a template edits, false creates.
    const [editing, setEditing] = useState<MessageTemplate | false | null>(null);
    const [removing, setRemoving] = useState<MessageTemplate | null>(null);
    const [viewing, setViewing] = useState<DeliveryRecord | null>(null);
    const [editingRule, setEditingRule] = useState<AutomationRule | null>(null);
    const [managing, setManaging] = useState(false);

    const campaigns = useCampaigns('email');
    const removeCampaign = useRemoveCampaign();
    const unscheduleCampaign = useUnscheduleCampaign();
    const sendCampaign = useSendCampaign();

    useEffect(() => {
        setSelected((current) => current ?? data?.templates[0] ?? null);
    }, [data?.templates]);

    if (isLoading) {
        return <LoadingBlock label="Reading the connection…" />;
    }

    if (isError || !data) {
        return (
            <Card>
                <ErrorState message={resolveErrorMessage(error)} onRetry={() => void refetch()} />
            </Card>
        );
    }

    return (
        <>
            <PageHeader
                title="Email communication"
                subtitle="Send professional emails to patients. Manage templates, automate notifications and track performance."
                icon="ti ti-mail"
                tone="sky"
                crumbs={[{ label: 'Communication' }, { label: 'Email' }]}
                actions={
                    manage && (
                        <Button icon="ti ti-plus" onClick={() => setEditing(false)}>
                            Create template
                        </Button>
                    )
                }
            />

            <div className="comm-tabs">
                <Tabs tabs={VIEWS} value={view} onChange={setView} label="Email views" />
            </div>

            <div className="row g-3">
                <div className="col-12 col-xxl-5">
                    <ChannelConnection
                        account={data.account}
                        tone="sky"
                        mark={<i className="ti ti-mail" />}
                        testLabel="Send test email"
                        onTest={manage ? () => setTesting(true) : undefined}
                        onManage={manage ? () => setManaging(true) : undefined}
                        facts={[
                            {
                                icon: 'ti ti-mail',
                                label: 'From address',
                                value: data.account.handle ?? 'Not set',
                            },
                            {
                                icon: 'ti ti-server',
                                label: 'Transport',
                                value: data.account.provider ?? 'Not set',
                            },
                        ]}
                    />
                </div>

                <div className="col-12 col-xxl-7">
                    <ChannelStats
                        tiles={tiles(data.figures)}
                        title="Email performance"
                        description={`Last ${days} days`}
                        actions={
                            <select
                                className="form-select comm-select"
                                value={days}
                                aria-label="Reporting period"
                                onChange={(event) => setDays(Number(event.target.value))}
                            >
                                <option value={7}>Last 7 days</option>
                                <option value={30}>Last 30 days</option>
                                <option value={90}>Last 90 days</option>
                            </select>
                        }
                    />
                </div>
            </div>

            <div className="comm-view">
                {view === 'overview' && (
                    <div className="row g-3">
                        <div className="col-12 col-xxl-8">
                            <RecentMessages messages={data.recent} typeLabel="Email type" onView={setViewing} />
                        </div>

                        <div className="col-12 col-xxl-4">
                            <ChannelBenefits title="Why use email?" benefits={EMAIL_BENEFITS} />
                        </div>
                    </div>
                )}

                {view === 'campaigns' && (
                    <CampaignsTable
                        channel="email"
                        campaigns={campaigns.data?.data ?? []}
                        loading={campaigns.isLoading}
                        busy={sendCampaign.isPending}
                        onNew={
                            manage ? () => navigate('/communication/email/campaigns/new') : undefined
                        }
                        onEdit={
                            manage
                                ? (item) => navigate(`/communication/email/campaigns/${item.id}`)
                                : undefined
                        }
                        onSchedule={
                            manage
                                ? (item) => navigate(`/communication/email/campaigns/${item.id}`)
                                : undefined
                        }
                        onUnschedule={
                            manage ? (item) => unscheduleCampaign.mutate(item.id) : undefined
                        }
                        onSend={manage ? (item) => {
                            /*
                             * The one irreversible action on this screen, so it
                             * asks — and it says the number, because "are you
                             * sure" without a count is a question nobody can
                             * answer.
                             */
                            const reach = item.recipients_count || 0;

                            if (
                                window.confirm(
                                    `Send "${item.name}" now? This cannot be taken back.` +
                                        (reach ? `

It will reach ${reach} patients.` : ''),
                                )
                            ) {
                                sendCampaign.mutate(item.id);
                            }
                        } : undefined}
                        onRemove={
                            manage ? (item) => removeCampaign.mutate({ id: item.id }) : undefined
                        }
                    />
                )}

                {view === 'templates' && (
                    <div className="row g-3">
                        <div className="col-12 col-xxl-8">
                            <TemplateTable
                                templates={data.templates}
                                categories={CATEGORIES}
                                selected={selected}
                                onSelect={setSelected}
                                filter="search"
                                actionLabel="Edit"
                                title="Email templates"
                                onCreate={manage ? () => setEditing(false) : undefined}
                                onEdit={manage ? setEditing : undefined}
                                onRemove={manage ? setRemoving : undefined}
                            />
                        </div>

                        <div className="col-12 col-xxl-4">
                            <EmailPreview
                                template={selected}
                                onEdit={manage ? setEditing : undefined}
                            />
                        </div>
                    </div>
                )}

                {view === 'automation' && (
                    <div className="row g-3">
                        <div className="col-12 col-xxl-8">
                            <AutomationRules
                                rules={data.rules}
                                saving={toggleRule.isPending}
                                onToggle={
                                    manage
                                        ? (rule) =>
                                              toggleRule.mutate({
                                                  id: rule.id,
                                                  enabled: !rule.is_enabled,
                                              })
                                        : undefined
                                }
                                onEdit={manage ? setEditingRule : undefined}
                                description="Automatically send emails based on clinic events"
                            />
                        </div>

                        <div className="col-12 col-xxl-4">
                            <ChannelBenefits title="Why automate?" benefits={EMAIL_BENEFITS} />
                        </div>
                    </div>
                )}

                {view === 'analytics' && (
                    <ChannelAnalyticsPanel
                        channel="email"
                        figures={data.figures}
                        analytics={data.analytics}
                        days={days}
                    />
                )}

                {view === 'settings' && (
                    <div className="row g-3">
                        <div className="col-12 col-xxl-5">
                            <SetupProgress
                                steps={data.setup}
                                onFinish={manage ? () => setTesting(true) : undefined}
                                finishLabel="Send a test email"
                            />
                        </div>

                        <div className="col-12 col-xxl-7">
                            <Card
                                className="comm-card"
                                title="Sending settings"
                                icon="ti ti-adjustments"
                                description="The address patients see, and how mail leaves the clinic"
                            >
                                <p className="comm-settings-copy">
                                    The address patients see is set under &ldquo;Manage
                                    connection&rdquo;. The mail server behind it is configured once
                                    for the whole platform by an administrator — every clinic sends
                                    through the same relay.
                                </p>
                            </Card>
                        </div>
                    </div>
                )}
            </div>

            <ComplianceNote />

            <TestSendModal
                open={testing}
                onClose={() => setTesting(false)}
                onSend={(payload) => sendTest.mutateAsync(payload)}
                templates={data.templates}
                sending={sendTest.isPending}
                channel="email"
                account={data.account}
                title="Send a test email"
                label="Email address"
                type="email"
                placeholder={data.account.handle ?? 'owner@careplus.com'}
                hint="Goes out through the same transport as a real notification, so a failure here is a real failure."
            />

            <TemplateFormModal
                open={editing !== null}
                channel="email"
                template={editing || null}
                categories={CATEGORIES}
                saving={saveTemplate.isPending}
                onClose={() => setEditing(null)}
                onSave={(values) => saveTemplate.mutateAsync(values)}
            />

            <ReasonDialog
                open={removing !== null}
                title={`Remove ${removing?.name ?? 'template'}?`}
                subtitle="It stops being available to send. Nothing already sent changes."
                submitLabel="Remove template"
                danger
                submitting={removeTemplate.isPending}
                error={removeTemplate.isError ? resolveErrorMessage(removeTemplate.error) : null}
                onClose={() => {
                    setRemoving(null);
                    removeTemplate.reset();
                }}
                onSubmit={(reason) => {
                    if (!removing) {
                        return;
                    }

                    removeTemplate.mutate(
                        { id: removing.id, reason },
                        {
                            onSuccess: () => {
                                setSelected((current) =>
                                    current?.id === removing.id ? null : current,
                                );
                                setRemoving(null);
                            },
                        },
                    );
                }}
            />
            <MessageDetailModal message={viewing} onClose={() => setViewing(null)} />

            <AutomationRuleModal
                rule={editingRule}
                templates={data.templates}
                saving={saveRule.isPending}
                onClose={() => setEditingRule(null)}
                onSave={(values) => saveRule.mutateAsync(values)}
            />

            <ConnectionModal
                open={managing}
                channel="email"
                account={data.account}
                saving={saveConnection.isPending}
                onClose={() => setManaging(false)}
                onSave={(values) => saveConnection.mutateAsync(values)}
            />


        </>
    );
}
