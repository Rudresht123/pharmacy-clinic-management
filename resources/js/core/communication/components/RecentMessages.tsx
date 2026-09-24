import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import { DeliveryPill } from './StatusPill';
import type { DeliveryRecord } from '../types';

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

/**
 * The last few messages, and what became of each.
 *
 * The first place anybody looks when a patient says they never heard from the
 * clinic — so the status column carries the answer, and a failure says why
 * rather than only that it failed.
 *
 * Scrolled inside a fixed height rather than growing. It is the one panel with
 * no natural end — it gains a row on every send — so left to itself it set the
 * height of the whole row and left the card beside it half empty. The header
 * stays put while the body moves, or the columns stop meaning anything two
 * rows down.
 */
export function RecentMessages({
    messages,
    typeLabel,
    onView,
}: {
    messages: DeliveryRecord[];
    typeLabel: string;
    onView: (message: DeliveryRecord) => void;
}) {
    return (
        <Card
            className="comm-card comm-log-card"
            title="Recent messages"
            icon="ti ti-history"
            description="Live log of sent notifications"
        >
            {messages.length === 0 ? (
                <p className="pd-quiet">
                    <i className="ti ti-mood-empty" aria-hidden="true" />
                    Nothing has been sent on this channel yet.
                </p>
            ) : (
                <div className="pf-table-wrap comm-log-scroll">
                    <table className="pf-table comm-table">
                        <thead>
                            {/* Narrow columns are fixed; the message type
                                takes the slack, or four short columns spread
                                across the width and read as mostly gaps. */}
                            <tr>
                                <th className="comm-log-name">Patient name</th>
                                <th>{typeLabel}</th>
                                <th className="comm-log-status">Status</th>
                                <th className="comm-log-when">Date &amp; time</th>
                                <th className="comm-actions-col">Action</th>
                            </tr>
                        </thead>

                        <tbody>
                            {messages.map((message) => (
                                <tr key={message.id}>
                                    <td className="pf-strong comm-log-name">
                                        {message.recipient_name ?? message.recipient}
                                    </td>

                                    <td className="comm-log-type">
                                        {message.template_name ?? '—'}
                                    </td>

                                    <td className="comm-log-status">
                                        <DeliveryPill status={message.status} />

                                        {message.failure_reason && (
                                            <span className="pd-sub">{message.failure_reason}</span>
                                        )}
                                    </td>

                                    <td className="comm-log-when">
                                        {when(message.sent_at ?? message.created_at)}
                                    </td>

                                    <td className="comm-actions-col">
                                        <Button
                                            variant="light"
                                            size="sm"
                                            onClick={() => onView(message)}
                                        >
                                            View
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </Card>
    );
}
