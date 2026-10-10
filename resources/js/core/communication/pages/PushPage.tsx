import { useEffect, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { Tabs, type TabItem } from '@/shared/components/ui/Tabs';
import { ErrorState, LoadingBlock } from '@/shared/components/ui/Feedback';
import { resolveErrorMessage } from '@/shared/api/http';
import { PushHero } from '../components/PushHero';
import { PushKpis } from '../components/PushKpis';
import { PushTemplateList } from '../components/PushTemplateList';
import { PushTemplatesPanel } from '../components/PushTemplatesPanel';
import { PushDevicesTable } from '../components/PushDevicesTable';
import { PushSendForm } from '../components/PushSendForm';
import { PushRecentTable } from '../components/PushRecentTable';
import { AutomationRules } from '../components/AutomationRules';
import { RecentMessages } from '../components/RecentMessages';
import { TemplateFormModal } from '../components/TemplateFormModal';
import { MessageDetailModal } from '../components/MessageDetailModal';
import { AutomationRuleModal } from '../components/AutomationRuleModal';
import { ChannelAnalyticsPanel } from '../components/ChannelAnalyticsPanel';
import { CampaignsTable } from '../components/CampaignsTable';
import { BranchWordingPanel } from '../components/BranchWordingPanel';
import { PushNotificationCard } from '../components/PushPreview';
import { fillPlaceholders } from '../placeholders';

import { ReasonDialog } from '@/core/medicines/components/ReasonDialog';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import {
    useCampaigns,
    useRemoveCampaign,
    useSendCampaign,
    useUnscheduleCampaign,
    useChannelOverview,
    usePushDevices,
    useRemoveTemplate,
    useSaveTemplate,
    useSaveConnection,
    useSaveRule,
    useSendTest,
    useToggleRule,
} from '../api';
import type { AutomationRule, DeliveryRecord, MessageTemplate } from '../types';

const CATEGORIES = ['All', 'Appointment', 'Reminder', 'Payments', 'Marketing', 'General'] as const;

type View = 'overview' | 'templates' | 'devices' | 'send' | 'history' | 'settings';

const VIEWS: readonly TabItem<View>[] = [
    { value: 'overview', label: 'Overview', icon: 'ti ti-layout-dashboard' },
    { value: 'templates', label: 'Templates', icon: 'ti ti-file-text' },
    { value: 'devices', label: 'Device tokens', icon: 'ti ti-device-mobile' },
    { value: 'send', label: 'Send notification', icon: 'ti ti-send' },
    { value: 'history', label: 'History', icon: 'ti ti-history' },
    { value: 'settings', label: 'Settings', icon: 'ti ti-settings' },
];

/**
 * Where the earlier tab names live now, so a bookmark — or the campaign
 * wizard's "back", which still says `?view=campaigns` — lands somewhere real.
 */
const MOVED: Record<string, View> = {
    campaigns: 'send',
    automation: 'settings',
    analytics: 'history',
};

/** The longest message the server accepts for an App notification. */
const BODY_LIMIT = 500;

/**
 * Push notifications through Firebase — a channel like email and WhatsApp:
 * its own templates, automation, campaigns, delivery log and analytics — plus
 * the one thing only this channel has, the phones themselves.
 *
 * Global → Organisation → Branch: the templates arrive as the platform's
 * defaults, the clinic rewords them, and each branch can reword them again.
 *
 * SETTING-BASED like the others. The channel arrives switched off and every
 * rule with it; nothing reaches a phone until the clinic switches the channel
 * on (the switch in the header), and a rule, with an approved template.
 */
export default function PushPage() {
    const navigate = useNavigate();
    const [searchParams, setSearchParams] = useSearchParams();

    const asked = searchParams.get('view') ?? 'overview';
    const view: View = MOVED[asked] ?? (VIEWS.some((item) => item.value === asked) ? (asked as View) : 'overview');
    const setView = (next: View) => setSearchParams(next === 'overview' ? {} : { view: next }, { replace: true });

    const [days, setDays] = useState(30);
    const [selected, setSelected] = useState<MessageTemplate | null>(null);

    const { can } = useTenantAuth();
    const manage = can('communication.manage');

    const { data, isLoading, isError, error, refetch } = useChannelOverview('push', days);

    // Only the totals: one row is enough to carry the summary.
    const devices = usePushDevices({ per_page: 1, days });

    const toggleRule = useToggleRule();
    const sendTest = useSendTest('push');
    const saveTemplate = useSaveTemplate('push');
    const removeTemplate = useRemoveTemplate();
    const saveRule = useSaveRule();
    const saveConnection = useSaveConnection('push');

    const [editing, setEditing] = useState<MessageTemplate | false | null>(null);
    const [removing, setRemoving] = useState<MessageTemplate | null>(null);
    const [viewing, setViewing] = useState<DeliveryRecord | null>(null);
    const [editingRule, setEditingRule] = useState<AutomationRule | null>(null);

    const campaigns = useCampaigns('push');
    const removeCampaign = useRemoveCampaign();
    const unscheduleCampaign = useUnscheduleCampaign();
    const sendCampaign = useSendCampaign();

    useEffect(() => {
        setSelected((current) => current ?? data?.templates[0] ?? null);
    }, [data?.templates]);

    if (isLoading) {
        return <LoadingBlock label="Loading push notifications…" />;
    }

    if (isError || !data) {
        return (
            <Card>
                <ErrorState message={resolveErrorMessage(error)} onRetry={() => void refetch()} />
            </Card>
        );
    }

    const on = data.account.is_connected;

    /** The channel's own switch — the first of the switches every message passes. */
    function toggleChannel() {
        const question = on
            ? 'Switch push notifications off? No patient will get a notification on their phone until it is switched back on.'
            : 'Switch push notifications on? Rules that are switched on will start notifying patients on their phones.';

        if (window.confirm(question)) {
            saveConnection.mutate({ is_connected: !on });
        }
    }

    /** A test goes to the sender's own phone, never to a patient. */
    function test() {
        sendTest.mutate({ recipients: [], templateId: selected?.id ?? null });
    }

    /** From the overview's list to the template's own view, with its preview. */
    function openTemplate(template: MessageTemplate) {
        setSelected(template);
        setView('templates');
    }

    const campaignList = campaigns.data?.data ?? [];

    const campaignsTable = (
        <CampaignsTable
            channel="push"
            campaigns={campaignList}
            loading={campaigns.isLoading}
            busy={sendCampaign.isPending}
            onNew={manage ? () => navigate('/communication/push/campaigns/new') : undefined}
            onEdit={manage ? (item) => navigate(`/communication/push/campaigns/${item.id}`) : undefined}
            onSchedule={manage ? (item) => navigate(`/communication/push/campaigns/${item.id}`) : undefined}
            onUnschedule={manage ? (item) => unscheduleCampaign.mutate(item.id) : undefined}
            onSend={
                manage
                    ? (item) => {
                          const reach = item.recipients_count || 0;

                          if (
                              window.confirm(
                                  `Send "${item.name}" now? This cannot be taken back.` +
                                      (reach ? `\n\nIt will reach ${reach} patients.` : ''),
                              )
                          ) {
                              sendCampaign.mutate(item.id);
                          }
                      }
                    : undefined
            }
            onRemove={manage ? (item) => removeCampaign.mutate({ id: item.id }) : undefined}
        />
    );

    return (
        <>
            <PageHeader
                title="Push notifications (Firebase)"
                subtitle="Send push notifications to patients and staff through Firebase Cloud Messaging"
                icon="ti ti-bell-ringing"
                tone="violet"
                crumbs={[{ label: 'Communication' }, { label: 'Push notifications' }]}
                actions={
                    <div className="ph-header-actions">
                        <select
                            className="form-select"
                            value={days}
                            aria-label="Reporting period"
                            onChange={(event) => setDays(Number(event.target.value))}
                        >
                            <option value={7}>Last 7 days</option>
                            <option value={30}>Last 30 days</option>
                            <option value={90}>Last 90 days</option>
                        </select>

                        {manage ? (
                            <button
                                type="button"
                                role="switch"
                                aria-checked={on}
                                className={`ph-switch${on ? ' is-on' : ''}`}
                                disabled={saveConnection.isPending}
                                onClick={toggleChannel}
                                title={on ? 'Stop notifying patients' : 'Start notifying patients'}
                            >
                                <span className="ph-switch-track" aria-hidden="true">
                                    <span className="ph-switch-thumb" />
                                </span>
                                {on ? 'Notifications on' : 'Notifications off'}
                            </button>
                        ) : (
                            <span className={`ph-state${on ? ' is-on' : ''}`}>
                                <span className="ph-state-dot" />
                                {on ? 'On' : 'Off'}
                            </span>
                        )}
                    </div>
                }
            />

            <div className="ph-tabs">
                <Tabs tabs={VIEWS} value={view} onChange={setView} label="Push notification views" />
            </div>

            {view === 'overview' && (
                <>
                    <PushKpis figures={data.figures} devices={devices.data?.meta.summary} days={days} />

                    <div className="ph-grid">
                        <PushTemplatesPanel
                            templates={data.templates}
                            rules={data.rules}
                            onOpen={openTemplate}
                            onCreate={manage ? () => setEditing(false) : undefined}
                            onEdit={manage ? setEditing : undefined}
                            onRemove={manage ? setRemoving : undefined}
                        />

                        <PushDevicesTable compact days={days} canManage={manage} />

                        {manage ? (
                            <PushSendForm on={on} templates={data.templates} />
                        ) : (
                            <Card className="ph-card" title="Send test notification" icon="ti ti-send-2">
                                <p className="ph-empty mb-0">Sending needs the Communication manage permission.</p>
                            </Card>
                        )}

                        <PushRecentTable
                            campaigns={campaignList}
                            loading={campaigns.isLoading}
                            onViewAll={() => setView('send')}
                            onOpen={manage ? (item) => navigate(`/communication/push/campaigns/${item.id}`) : undefined}
                        />
                    </div>
                </>
            )}

            {view === 'templates' && (
                <div className="ph-templates">
                    <PushTemplateList
                        templates={data.templates}
                        categories={CATEGORIES}
                        selected={selected}
                        onSelect={setSelected}
                        onCreate={manage ? () => setEditing(false) : undefined}
                        onEdit={manage ? setEditing : undefined}
                        onRemove={manage ? setRemoving : undefined}
                    />

                    {/* Its own column of cards at their natural height —
                        never stretched to the list's, which is what cut the
                        bottom of the phone off. */}
                    <div className="ph-side">
                        <Card
                            className="ph-card"
                            title="Preview"
                            icon="ti ti-device-mobile"
                            description="As a patient sees it, with sample details filled in"
                        >
                            {selected ? (
                                <>
                                    <PushNotificationCard
                                        title={fillPlaceholders(selected.subject ?? selected.name)}
                                        body={fillPlaceholders(selected.content)}
                                    />

                                    <dl className="ph-measure">
                                        <div>
                                            <dt>Title</dt>
                                            <dd>{(selected.subject ?? selected.name).length} characters</dd>
                                        </div>
                                        <div>
                                            <dt>Message</dt>
                                            <dd>
                                                {selected.content.length} of {BODY_LIMIT}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt>Status</dt>
                                            <dd>{selected.is_active ? 'In use' : 'Not in use'}</dd>
                                        </div>
                                    </dl>
                                </>
                            ) : (
                                <p className="ph-empty">
                                    <i className="ti ti-hand-click" aria-hidden="true" />
                                    Choose a template to see it on a phone.
                                </p>
                            )}
                        </Card>

                        <BranchWordingPanel template={selected} />
                    </div>
                </div>
            )}

            {view === 'devices' && <PushDevicesTable days={days} canManage={manage} />}

            {view === 'send' && (
                <div className="ph-split">
                    {campaignsTable}
                    {manage && <PushSendForm on={on} templates={data.templates} />}
                </div>
            )}

            {view === 'history' && (
                <div className="d-flex flex-column gap-3">
                    <RecentMessages messages={data.recent} typeLabel="Notification" onView={setViewing} />
                    <ChannelAnalyticsPanel channel="push" figures={data.figures} analytics={data.analytics} days={days} />
                </div>
            )}

            {view === 'settings' && (
                <>
                    <PushHero
                        on={on}
                        steps={data.setup}
                        rulesOn={data.rules.filter((rule) => rule.is_enabled).length}
                        rulesTotal={data.rules.length}
                        testing={sendTest.isPending}
                        templates={data.templates}
                        templateId={selected?.id ?? null}
                        onTemplateChange={(id) => setSelected(data.templates.find((template) => template.id === id) ?? null)}
                        // The switch itself is in the header, on every tab; a
                        // second one here would be two controls for one fact.
                        onTest={manage ? test : undefined}
                    />

                    <AutomationRules
                        rules={data.rules}
                        connected={on}
                        templates={data.templates}
                        saving={toggleRule.isPending}
                        onToggle={
                            manage ? (rule) => toggleRule.mutate({ id: rule.id, enabled: !rule.is_enabled }) : undefined
                        }
                        onEdit={manage ? setEditingRule : undefined}
                        description="Notify patients on their phone when something happens at the clinic"
                    />

                    {/* So testing a template and seeing what it actually did
                        are the same screen — no tab switch to check a send
                        just made landed, or why it didn't. */}
                    <RecentMessages messages={data.recent} typeLabel="Notification" onView={setViewing} />
                </>
            )}

            <TemplateFormModal
                open={editing !== null}
                channel="push"
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
                                setSelected((current) => (current?.id === removing.id ? null : current));
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
        </>
    );
}
