import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import { CampaignPill } from './StatusPill';
import type { Campaign } from '../types';

function when(value: string | null): string {
    return value
        ? new Date(value).toLocaleString(undefined, {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
              hour: 'numeric',
              minute: '2-digit',
          })
        : '—';
}

/** Who it went to, in the words the campaign was set up with. */
function audience(campaign: Campaign): string {
    if (campaign.segment_name) {
        return campaign.segment_name;
    }

    return campaign.audience_filters.length > 0 ? 'Patients (filtered)' : 'Patients (all)';
}

/**
 * The notifications sent to groups of patients — one row per send, with how
 * many reached a phone and how many did not.
 *
 * Campaigns only. The automatic ones (a confirmation, a reminder) go to one
 * patient at a time and are counted in the figures above; listing each of
 * them here would bury the sends somebody chose to make. They are all in
 * History.
 */
export function PushRecentTable({
    campaigns,
    loading,
    onViewAll,
    onOpen,
}: {
    campaigns: Campaign[];
    loading?: boolean;
    onViewAll: () => void;
    onOpen?: (campaign: Campaign) => void;
}) {
    const recent = [...campaigns]
        .sort((a, b) => {
            const at = (c: Campaign) => c.completed_at ?? c.started_at ?? c.scheduled_at ?? '';

            return at(b).localeCompare(at(a));
        })
        .slice(0, 5);

    return (
        <Card
            className="ph-card"
            title="Recent push notifications"
            icon="ti ti-history"
            description="Sent to groups of patients"
            actions={
                <Button size="sm" variant="outline" className="ph-outline" onClick={onViewAll}>
                    View all
                </Button>
            }
        >
            {loading ? null : recent.length === 0 ? (
                <p className="ph-empty">
                    <i className="ti ti-send-off" aria-hidden="true" />
                    Nothing sent to a group yet. Automatic notifications are listed under History.
                </p>
            ) : (
                <div className="ph-table-wrap">
                    <table className="ph-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Title</th>
                                <th>Sent to</th>
                                <th>Sent at</th>
                                <th>Status</th>
                                <th className="text-end">Delivered</th>
                                <th className="text-end">Failed</th>
                                {onOpen && <th aria-label="Open" />}
                            </tr>
                        </thead>

                        <tbody>
                            {recent.map((campaign, index) => (
                                <tr key={campaign.id}>
                                    <td className="ph-num">{index + 1}</td>
                                    <td className="ph-strong">{campaign.name}</td>
                                    <td className="ph-quiet">{audience(campaign)}</td>
                                    <td className="ph-quiet">
                                        {when(campaign.completed_at ?? campaign.started_at ?? campaign.scheduled_at)}
                                    </td>
                                    <td>
                                        <CampaignPill status={campaign.status} />
                                    </td>
                                    <td className="text-end ph-strong">
                                        {campaign.delivered_count.toLocaleString('en-IN')}
                                    </td>
                                    <td className={`text-end ph-strong${campaign.failed_count > 0 ? ' ph-bad' : ''}`}>
                                        {campaign.failed_count.toLocaleString('en-IN')}
                                    </td>
                                    {onOpen && (
                                        <td className="text-end">
                                            <button
                                                type="button"
                                                className="ph-icon-btn"
                                                title="Open"
                                                aria-label={`Open ${campaign.name}`}
                                                onClick={() => onOpen(campaign)}
                                            >
                                                <i className="ti ti-chevron-right" />
                                            </button>
                                        </td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </Card>
    );
}
