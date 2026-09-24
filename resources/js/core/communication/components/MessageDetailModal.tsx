import { Modal } from '@/shared/components/ui/Modal';
import { Button } from '@/shared/components/ui/Button';
import { DeliveryPill } from './StatusPill';
import type { DeliveryRecord } from '../types';

function stamp(value: string | null): string | null {
    return value
        ? new Date(value).toLocaleString(undefined, {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
              hour: 'numeric',
              minute: '2-digit',
          })
        : null;
}

/**
 * One message, and exactly how far it got.
 *
 * The status pill in the log says where it ended; this says how it got there.
 * "Delivered at 2:29 and read at 4:05" is a different answer from "read", and
 * it is the one that settles an argument about whether a patient was actually
 * told — which is the only reason anybody opens a delivery log.
 *
 * A step with no timestamp is drawn as not reached rather than hidden, because
 * the gap is the information: a message that was sent and never delivered is
 * a different failure from one that never left.
 */
export function MessageDetailModal({
    message,
    onClose,
}: {
    /** Null closes it; the record opens it. */
    message: DeliveryRecord | null;
    onClose: () => void;
}) {
    const steps = message
        ? [
              {
                  key: 'queued',
                  label: 'Queued',
                  hint: 'Accepted and waiting to go out',
                  at: stamp(message.created_at),
              },
              {
                  key: 'sent',
                  label: 'Sent',
                  hint: 'Handed to the provider',
                  at: stamp(message.sent_at),
              },
              {
                  key: 'delivered',
                  label: 'Delivered',
                  hint: 'Arrived on the device',
                  at: stamp(message.delivered_at),
              },
              {
                  key: 'read',
                  label: 'Read',
                  hint: 'Opened by the patient',
                  at: stamp(message.read_at),
              },
          ]
        : [];

    return (
        <Modal
            open={message !== null}
            title="Message detail"
            subtitle={message?.template_name ?? 'Delivery record'}
            onClose={onClose}
            size="md"
            icon={<i className="ti ti-message-circle" />}
            footer={
                <Button variant="light" onClick={onClose}>
                    Close
                </Button>
            }
        >
            {message && (
                <div className="comm-detail">
                    <div className="comm-detail-head">
                        <div>
                            <small>Sent to</small>
                            <b>{message.recipient_name ?? 'Not a patient record'}</b>
                            <span>{message.recipient}</span>
                        </div>

                        <DeliveryPill status={message.status} />
                    </div>

                    {message.is_test && (
                        <p className="pf-soon">
                            <i className="ti ti-flask" aria-hidden="true" />
                            A test message. It proves the connection and is deliberately left out
                            of the delivery rates.
                        </p>
                    )}

                    {message.failure_reason && (
                        <div className="comm-test-result is-fail">
                            <i className="ti ti-alert-triangle" aria-hidden="true" />

                            <div>
                                <b>This did not arrive</b>
                                <span>{message.failure_reason}</span>
                            </div>
                        </div>
                    )}

                    <div>
                        <h6 className="comm-subhead">Progress</h6>

                        <ol className="comm-timeline">
                            {steps.map((step) => (
                                <li key={step.key} className={step.at ? 'is-done' : undefined}>
                                    <i
                                        className={
                                            step.at
                                                ? 'ti ti-circle-check-filled'
                                                : 'ti ti-circle-dashed'
                                        }
                                        aria-hidden="true"
                                    />

                                    <div>
                                        <b>{step.label}</b>
                                        <small>{step.at ?? step.hint}</small>
                                    </div>
                                </li>
                            ))}
                        </ol>
                    </div>

                    <dl className="pf-rows">
                        <div>
                            <dt>Template</dt>
                            <dd>{message.template_name ?? '—'}</dd>
                        </div>

                        {message.subject && (
                            <div>
                                <dt>Subject</dt>
                                <dd>{message.subject}</dd>
                            </div>
                        )}

                        <div>
                            <dt>Channel</dt>
                            <dd className="text-capitalize">{message.channel}</dd>
                        </div>

                        {message.campaign_id !== null && (
                            <div>
                                <dt>Campaign</dt>
                                <dd>Part of a bulk send (#{message.campaign_id})</dd>
                            </div>
                        )}
                    </dl>
                </div>
            )}
        </Modal>
    );
}
