import type { ReactNode } from 'react';
import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import type { ChannelAccount } from '../types';

/** The clinic's own date format, from an ISO string the API sends. */
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

/**
 * The account a channel sends from.
 *
 * Identity, then the buttons, then the wiring: who the patient sees this
 * from, what you can do about it, and how it is actually hooked up — the
 * order somebody checking a broken connection asks them in.
 *
 * Disconnected is a real state and the common one on a new clinic, so the card
 * has to read correctly with nothing filled in rather than assuming a live
 * account.
 */
export function ChannelConnection({
    account,
    mark,
    tone,
    testLabel,
    onTest,
    onManage,
    onConfigure,
    facts,
}: {
    account: ChannelAccount;
    mark: ReactNode;
    tone: 'emerald' | 'sky';
    testLabel: string;
    /**
     * Absent where the signed-in person may not do it.
     *
     * A prop rather than a capability check inside the card: the card is used
     * by both channels and knows nothing about who is looking at it. The page
     * holds the capability and simply does not pass the handler — so the
     * button is not rendered disabled, it does not exist, because a button
     * that can only ever answer 403 is worse than no button.
     */
    onTest?: () => void;
    onManage?: () => void;
    /**
     * The wiring behind the account, where a channel has any to configure.
     *
     * A prop rather than a channel check inside this card: WhatsApp's provider
     * is a superadmin's and has no button here at all, while email's mail
     * server is the clinic's own.
     */
    onConfigure?: { label: string; icon: string; title: string; onClick: () => void };
    /** Channel-specific rows for the right-hand column. */
    facts: { icon: string; label: string; value: string }[];
}) {
    const connected = account.is_connected;

    return (
        <Card className="comm-card">
            <div className="comm-connection">
                <div className="comm-who">
                    <span className={`comm-mark is-${tone}`} aria-hidden="true">
                        {mark}
                        {connected && <span className="comm-dot" />}
                    </span>

                    <div className="comm-who-body">
                        <span className={`comm-pill ${connected ? 'is-live' : 'is-muted'}`}>
                            {connected ? 'Connected' : 'Not connected'}
                        </span>

                        <h6 className="comm-name">
                            {account.display_name ?? 'No account yet'}

                            {account.is_verified && (
                                <i
                                    className="ti ti-rosette-discount-check-filled"
                                    title="Verified business"
                                />
                            )}
                        </h6>

                        <p className="comm-handle">{account.handle ?? 'Nothing to send from'}</p>

                        <p className="comm-since">
                            {connected
                                ? `Connected on ${when(account.connected_at)}`
                                : 'Connect an account to start sending'}
                        </p>
                    </div>
                </div>

                {(onConfigure || onManage || onTest) && (
                    <div className="comm-connection-actions">
                        {onConfigure && (
                            <Button
                                variant="light"
                                icon={onConfigure.icon}
                                onClick={onConfigure.onClick}
                                title={onConfigure.title}
                            >
                                {onConfigure.label}
                            </Button>
                        )}

                        {onManage && (
                            <Button variant="light" icon="ti ti-settings" onClick={onManage}>
                                Manage connection
                            </Button>
                        )}

                        {onTest && (
                            <Button icon="ti ti-send" onClick={onTest} disabled={!connected}>
                                {testLabel}
                            </Button>
                        )}
                    </div>
                )}

                <div className="comm-facts">
                    <h6 className="comm-subhead">Business account</h6>

                    <ul>
                        {facts.map((fact) => (
                            <li key={fact.label}>
                                <i className={fact.icon} aria-hidden="true" />
                                <span>{fact.label}</span>
                                {/* Truncated when long, so the label keeps its room. */}
                                <b title={fact.value}>{fact.value}</b>
                            </li>
                        ))}

                        <li>
                            <i className="ti ti-activity" aria-hidden="true" />
                            <span>Status</span>
                            <b>
                                <span className={`comm-pill ${connected ? 'is-live' : 'is-muted'}`}>
                                    {connected ? 'Active' : 'Inactive'}
                                </span>
                            </b>
                        </li>
                    </ul>
                </div>
            </div>
        </Card>
    );
}
