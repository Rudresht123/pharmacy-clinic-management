import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { Tabs, type TabItem } from '@/shared/components/ui/Tabs';
import { ErrorState, LoadingBlock } from '@/shared/components/ui/Feedback';
import type { StatTile } from '@/shared/components/ui/StatTiles';
import { resolveErrorMessage } from '@/shared/api/http';
import { ChannelConnection } from '../components/ChannelConnection';
import { SetupProgress } from '../components/SetupProgress';
import { ChannelBenefits, WHATSAPP_BENEFITS } from '../components/ChannelBenefits';
import { ChannelStats } from '../components/ChannelStats';
import { TemplateTable } from '../components/TemplateTable';
import { WhatsAppPreview } from '../components/WhatsAppPreview';
import { AutomationRules } from '../components/AutomationRules';
import { RecentMessages } from '../components/RecentMessages';
import { ComplianceNote } from '../components/ComplianceNote';
import { TestSendModal } from '../components/TestSendModal';
import { TemplateFormModal } from '../components/TemplateFormModal';
import { MessageDetailModal } from '../components/MessageDetailModal';
import { AutomationRuleModal } from '../components/AutomationRuleModal';
import { ConnectionModal } from '../components/ConnectionModal';
import { ChannelAnalyticsPanel } from '../components/ChannelAnalyticsPanel';
import { ReasonDialog } from '@/core/medicines/components/ReasonDialog';
import {
    useChannelOverview,
    useRemoveTemplate,
    useSaveTemplate,
    useSaveConnection,
    useSaveRule,
    useSendTest,
    useToggleRule,
} from '../api';
import type { AutomationRule, ChannelFigures, DeliveryRecord, MessageTemplate } from '../types';

const CATEGORIES = ['All', 'Appointment', 'Reminder', 'Payments', 'Follow-up'] as const;

type View = 'overview' | 'templates' | 'automation' | 'analytics' | 'settings';

/**
 * The same five views the email screen has.
 *
 * This page used to be one long scroll — connection, figures, reporting,
 * templates, rules, log and compliance stacked end to end. Everything past the
 * charts was three screens down and nobody scrolled that far, so the template
 * library and the automation rules were effectively hidden.
 */
const VIEWS: readonly TabItem<View>[] = [
    { value: 'overview', label: 'Overview', icon: 'ti ti-layout-dashboard' },
    { value: 'templates', label: 'Templates', icon: 'ti ti-template' },
    { value: 'automation', label: 'Automation', icon: 'ti ti-settings-automation' },
    { value: 'analytics', label: 'Analytics', icon: 'ti ti-chart-bar' },
    { value: 'settings', label: 'Settings', icon: 'ti ti-adjustments' },
];

/** A count with no thousands separator reads as a different number. */
function count(value: number): string {
    return value.toLocaleString('en-IN');
}

/**
 * Sent, delivered, read, failed — each a subset of the one before it.
 *
 * Hints say what the rate is a proportion OF, and go quiet rather than showing
 * "0%" when there is nothing to divide by: "no messages yet" and "nothing was
 * delivered" are different answers.
 */
function tiles(figures: ChannelFigures): StatTile[] {
    const change =
        figures.change === null
            ? `Last ${figures.days} days`
            : `${figures.change >= 0 ? 'up' : 'down'} ${Math.abs(figures.change)}% on the previous ${figures.days} days`;

    return [
        {
            label: 'Messages sent',
            value: count(figures.sent),
            icon: 'ti ti-send',
            tone: 'sky',
            hint: change,
        },
        {
            label: 'Delivered',
            value: count(figures.delivered),
            icon: 'ti ti-circle-check',
            tone: 'emerald',
            hint: figures.delivery_rate === null ? 'Nothing sent yet' : `${figures.delivery_rate}% delivery rate`,
        },
        {
            label: 'Read',
            value: count(figures.read),
            icon: 'ti ti-eye',
            tone: 'teal',
            hint: figures.read_rate === null ? 'Nothing delivered yet' : `${figures.read_rate}% of what arrived`,
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
 * WhatsApp, for a clinic.
 *
 * Everything on this screen is read from the tenant's own rows: the figures
 * are counted from the delivery log, the checklist is derived from the state
 * it describes, and the templates and rules are the clinic's own. Nothing here
 * is standing data — a clinic that has sent nothing sees zeroes and a checklist
 * with work left, which is the truth.
 *
 * One request fills it, because the checklist and the template list are read
 * from the same state and arriving apart would let the page claim to be ready
 * before it is.
 */
export default function WhatsAppPage() {
    const [searchParams, setSearchParams] = useSearchParams();

    const view = (searchParams.get('view') as View | null) ?? 'overview';

    const setView = (next: View) =>
        setSearchParams(next === 'overview' ? {} : { view: next }, { replace: true });

    const [days, setDays] = useState(30);

    const { data, isLoading, isError, error, refetch } = useChannelOverview('whatsapp', days);

    const toggleRule = useToggleRule();
    const sendTest = useSendTest('whatsapp');
    const saveTemplate = useSaveTemplate('whatsapp');
    const removeTemplate = useRemoveTemplate();
    const saveRule = useSaveRule();
    const saveConnection = useSaveConnection('whatsapp');

    const [selected, setSelected] = useState<MessageTemplate | null>(null);
    const [testing, setTesting] = useState(false);

    /*
     * Null means closed; a template means edit; `false` means create.
     * Three states in one value, because "the form is open on nothing" and
     * "the form is closed" are different and a separate boolean drifts.
     */
    const [editing, setEditing] = useState<MessageTemplate | false | null>(null);
    const [removing, setRemoving] = useState<MessageTemplate | null>(null);
    const [viewing, setViewing] = useState<DeliveryRecord | null>(null);
    const [editingRule, setEditingRule] = useState<AutomationRule | null>(null);
    const [managing, setManaging] = useState(false);

    // The first template, once there is one — and left alone afterwards, so a
    // refetch does not move somebody's selection out from under them.
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
                title="WhatsApp integration"
                subtitle="Connect WhatsApp to communicate with patients and automate clinic notifications."
                icon="ti ti-brand-whatsapp"
                tone="emerald"
                crumbs={[{ label: 'Communication' }, { label: 'WhatsApp' }]}
            />

            <div className="row g-3">
                <div className="col-12 col-xxl-8">
                    <ChannelConnection
                        account={data.account}
                        tone="emerald"
                        mark={<i className="ti ti-brand-whatsapp" />}
                        testLabel="Test message"
                        onTest={() => setTesting(true)}
                        onManage={() => setManaging(true)}
                        facts={[
                            {
                                icon: 'ti ti-id',
                                label: 'Account type',
                                value: data.account.provider ?? 'Not set',
                            },
                            {
                                icon: 'ti ti-phone',
                                label: 'Phone number',
                                value: data.account.handle ?? 'Not set',
                            },
                            {
                                icon: 'ti ti-building',
                                label: 'Business name',
                                value: String(data.account.settings.business_name ?? '—'),
                            },
                        ]}
                    />
                </div>

                <div className="col-12 col-xxl-4">
                    <SetupProgress steps={data.setup} onFinish={() => setTesting(true)} />
                </div>
            </div>

            <ChannelStats tiles={tiles(data.figures)} />

            {/* The charts behind the tiles, in the same request so the two
                cannot disagree across a moving window. */}
            <div className="comm-report-head">
                <div>
                    <h6>Reporting</h6>
                    <p>Counted from the delivery log — nothing here is estimated.</p>
                </div>

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
            </div>

            <ChannelAnalyticsPanel
                channel="whatsapp"
                figures={data.figures}
                analytics={data.analytics}
                days={days}
            />

            <div className="row g-3">
                <div className="col-12 col-xxl-8">
                    <TemplateTable
                        templates={data.templates}
                        categories={CATEGORIES}
                        selected={selected}
                        onSelect={setSelected}
                        onCreate={() => setEditing(false)}
                        onEdit={setEditing}
                        onRemove={setRemoving}
                    />
                </div>

                <div className="col-12 col-xxl-4">
                    <WhatsAppPreview template={selected} onEdit={setEditing} />
                </div>
            </div>

            <div className="row g-3 mt-0">
                <div className="col-12 col-xxl-5">
                    <AutomationRules
                        rules={data.rules}
                        saving={toggleRule.isPending}
                        onToggle={(rule) =>
                            toggleRule.mutate({ id: rule.id, enabled: !rule.is_enabled })
                        }
                        onEdit={setEditingRule}
                        description="Automatically send WhatsApp messages based on clinic events"
                    />
                </div>

                <div className="col-12 col-xxl-7">
                    <RecentMessages messages={data.recent} typeLabel="Message type" onView={setViewing} />
                </div>
            </div>

            <div className="row g-3 mt-0">
                <div className="col-12 col-xxl-8">
                    <ComplianceNote />
                </div>

                <div className="col-12 col-xxl-4">
                    <ChannelBenefits title="Why use WhatsApp?" benefits={WHATSAPP_BENEFITS} />
                </div>
            </div>

            <TestSendModal
                open={testing}
                onClose={() => setTesting(false)}
                onSend={(payload) => sendTest.mutateAsync(payload)}
                templates={data.templates}
                sending={sendTest.isPending}
                channel="whatsapp"
                account={data.account}
                title="Send a test message"
                label="Phone number"
                placeholder={data.account.handle ?? '+91 98765 43210'}
                hint="Use a number that has messaged this business before, or WhatsApp will block the test rather than the clinic."
            />

            <TemplateFormModal
                open={editing !== null}
                channel="whatsapp"
                template={editing || null}
                categories={CATEGORIES}
                saving={saveTemplate.isPending}
                onClose={() => setEditing(null)}
                onSave={(values) => saveTemplate.mutateAsync(values)}
            />

            {/*
                The server refuses a template an automation rule still sends,
                and names the rule — so its answer is shown here rather than a
                generic failure.
            */}
            <ReasonDialog
                open={removing !== null}
                title={`Remove ${removing?.name ?? 'template'}?`}
                subtitle="It stops being available to send. Nothing already sent changes."
                submitLabel="Remove template"
                danger
                submitting={removeTemplate.isPending}
                error={
                    removeTemplate.isError ? resolveErrorMessage(removeTemplate.error) : null
                }
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
                                // The preview may have been showing it.
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
                channel="whatsapp"
                account={data.account}
                saving={saveConnection.isPending}
                onClose={() => setManaging(false)}
                onSave={(values) => saveConnection.mutateAsync(values)}
            />
        </>
    );
}
