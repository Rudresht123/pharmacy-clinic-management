import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import { CampaignPill } from './StatusPill';
import type { Campaign, Channel } from '../types';

function when(value: string | null): string {
    return value
        ? new Date(value).toLocaleString(undefined, {
              day: '2-digit',
              month: 'short',
              hour: 'numeric',
              minute: '2-digit',
          })
        : '—';
}

function count(value: number | null | undefined): string {
    return (value ?? 0).toLocaleString('en-IN');
}

/**
 * How many a campaign will reach — once that has actually been worked out.
 *
 * A draft's audience is resolved when it is scheduled, not when it is written.
 * Printing 0 until then says "nobody matches", which is a different answer and
 * the one that sends somebody back to rebuild filters that were fine.
 */
function audience(campaign: Campaign): string {
    return campaign.status === 'draft' ? '—' : count(campaign.recipients_count);
}

/**
 * A rate that is not being measured, shown as unmeasured.
 *
 * Open and click tracking needs a pixel and rewritten links, and neither
 * exists. Rendering 0% would say every patient ignored every email — a clinic
 * would read that as a content problem and rewrite templates that were fine.
 * An em dash says "not counted", which is the truth.
 */
function rate(value: number | null): string {
    return value === null || value === undefined ? '—' : `${value}%`;
}

/**
 * Every campaign on this channel, and what can still be done to each.
 *
 * The actions are state-dependent and that is the whole design: a draft can be
 * scheduled, a scheduled one can be called back, a completed one can only be
 * read. Showing all of them always and failing on click would teach people to
 * distrust the buttons.
 */
export function CampaignsTable({
    channel,
    campaigns,
    loading,
    onNew,
    onEdit,
    onSchedule,
    onUnschedule,
    onSend,
    onRemove,
    busy,
}: {
    channel: Channel;
    campaigns: Campaign[];
    loading?: boolean;
    /*
     * All six absent for somebody who may read the log but not send.
     *
     * The row's own gating below is a SECOND question, asked of the campaign
     * rather than of the person: a sent campaign cannot be edited by anybody.
     * Both have to pass before a button exists, and the server asks both again.
     */
    onNew?: () => void;
    onEdit?: (campaign: Campaign) => void;
    onSchedule?: (campaign: Campaign) => void;
    onUnschedule?: (campaign: Campaign) => void;
    onSend?: (campaign: Campaign) => void;
    onRemove?: (campaign: Campaign) => void;
    busy?: boolean;
}) {
    const label = channel === 'email' ? 'Email' : 'WhatsApp';
    const actionable = Boolean(onEdit || onSchedule || onUnschedule || onSend || onRemove);

    return (
        <Card
            className="comm-card"
            title="Campaigns"
            icon="ti ti-send"
            description={`One-off ${label.toLowerCase()} sends to a chosen audience`}
            actions={
                onNew && (
                    <Button icon="ti ti-plus" onClick={onNew} disabled={busy}>
                        New campaign
                    </Button>
                )
            }
        >
            {loading ? (
                <p className="pd-quiet">
                    <i className="ti ti-loader-2 comm-spin" aria-hidden="true" />
                    Loading campaigns…
                </p>
            ) : campaigns.length === 0 ? (
                <p className="pd-quiet">
                    <i className="ti ti-mood-empty" aria-hidden="true" />
                    No campaigns yet. A campaign reaches a chosen group once — automations are for
                    the messages that go every time.
                </p>
            ) : (
                <div className="pf-table-wrap">
                    <table className="pf-table comm-table">
                        <thead>
                            <tr>
                                <th>Campaign</th>
                                <th>Status</th>
                                <th>When</th>
                                <th className="text-end">Audience</th>
                                <th className="text-end">Sent</th>
                                <th className="text-end">Delivered</th>
                                <th className="text-end">Opened</th>
                                <th className="text-end">Clicked</th>
                                {actionable && <th className="comm-actions-col">Actions</th>}
                            </tr>
                        </thead>

                        <tbody>
                            {campaigns.map((campaign) => (
                                <tr key={campaign.id}>
                                    <td className="pf-strong">
                                        <span className="comm-row-name">
                                            <span>
                                                <b>{campaign.name}</b>

                                                {/* Only when it says something
                                                    the name does not — a
                                                    subject identical to the
                                                    name is the same line
                                                    printed twice. */}
                                                {campaign.subject &&
                                                    campaign.subject !== campaign.name && (
                                                        <small className="comm-row-sub">
                                                            {campaign.subject}
                                                        </small>
                                                    )}

                                                {!campaign.subject && campaign.goal && (
                                                    <small className="comm-row-sub">
                                                        {campaign.goal}
                                                    </small>
                                                )}
                                            </span>
                                        </span>
                                    </td>

                                    <td>
                                        <CampaignPill status={campaign.status} />

                                        {campaign.failure_reason && (
                                            <small
                                                className="comm-row-sub"
                                                title={campaign.failure_reason}
                                            >
                                                {campaign.failure_reason}
                                            </small>
                                        )}
                                    </td>

                                    <td>
                                        {when(
                                            campaign.completed_at ??
                                                campaign.started_at ??
                                                campaign.scheduled_at,
                                        )}
                                    </td>

                                    <td
                                        className="text-end"
                                        title={
                                            campaign.status === 'draft'
                                                ? 'Counted when the campaign is scheduled or sent'
                                                : undefined
                                        }
                                    >
                                        {audience(campaign)}
                                    </td>
                                    <td className="text-end">{count(campaign.sent_count)}</td>
                                    <td className="text-end">{count(campaign.delivered_count)}</td>

                                    {/* Not counted rather than zero — see rate(). */}
                                    <td className="text-end" title="Open tracking is not wired up">
                                        {rate(campaign.open_rate)}
                                    </td>

                                    <td className="text-end" title="Click tracking is not wired up">
                                        {rate(campaign.click_rate)}
                                    </td>

                                    {actionable && (
                                    <td className="comm-actions-col">
                                        <div className="comm-row-actions">
                                            {campaign.is_editable && onEdit && (
                                                <button
                                                    type="button"
                                                    className="comm-more"
                                                    title="Edit"
                                                    onClick={() => onEdit(campaign)}
                                                >
                                                    <i className="ti ti-edit" aria-hidden="true" />
                                                </button>
                                            )}

                                            {campaign.status === 'draft' && onSchedule && (
                                                <button
                                                    type="button"
                                                    className="comm-more"
                                                    title="Schedule"
                                                    onClick={() => onSchedule(campaign)}
                                                >
                                                    <i className="ti ti-clock" aria-hidden="true" />
                                                </button>
                                            )}

                                            {campaign.status === 'draft' && onSend && (
                                                <button
                                                    type="button"
                                                    className="comm-more"
                                                    title="Send now"
                                                    onClick={() => onSend(campaign)}
                                                >
                                                    <i className="ti ti-send" aria-hidden="true" />
                                                </button>
                                            )}

                                            {campaign.status === 'scheduled' && onUnschedule && (
                                                <button
                                                    type="button"
                                                    className="comm-more"
                                                    title="Cancel the schedule"
                                                    onClick={() => onUnschedule(campaign)}
                                                >
                                                    <i
                                                        className="ti ti-clock-off"
                                                        aria-hidden="true"
                                                    />
                                                </button>
                                            )}

                                            {campaign.is_editable && onRemove && (
                                                <button
                                                    type="button"
                                                    className="comm-more is-danger"
                                                    title="Remove"
                                                    onClick={() => onRemove(campaign)}
                                                >
                                                    <i className="ti ti-trash" aria-hidden="true" />
                                                </button>
                                            )}
                                        </div>
                                    </td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            <p className="pf-soon">
                <i className="ti ti-info-circle" aria-hidden="true" />
                Opened and clicked read &ldquo;—&rdquo; because tracking is not wired up. Sent and
                delivered are counted from the delivery log and are real.
            </p>
        </Card>
    );
}
