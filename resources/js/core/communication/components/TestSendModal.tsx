import { useEffect, useRef, useState, type ClipboardEvent, type KeyboardEvent } from 'react';
import { Modal } from '@/shared/components/ui/Modal';
import { Button } from '@/shared/components/ui/Button';
import { TemplatePreview } from './TemplatePreview';
import type { Channel, ChannelAccount, MessageTemplate } from '../types';

/** A test proves a connection; past a handful it is an unaudited broadcast. */
const MAX = 10;

/** Anything somebody might paste between addresses. */
const SEPARATORS = /[\s,;]+/;

/** What goes out when no template is chosen. */
const PLAIN =
    'This is a test message from {{clinic_name}}. If you are reading this, the connection works.';

/**
 * Send a test, and see exactly what will arrive.
 *
 * Three things a test has to answer, in order: what leaves, where it goes, and
 * what lands. The dialog is built in that order.
 *
 * A TEMPLATE can be chosen, because the question is usually not "does the
 * connection work" in the abstract — it is "does the appointment reminder look
 * right on a real phone", and the only way to find out is to send that one.
 * Nothing chosen sends a plain line, which is the right answer the first time
 * anybody opens this.
 *
 * SEVERAL addresses, because checking a connection usually means checking it
 * reaches the people who will rely on it — the front desk, the owner's phone,
 * the doctor's — and one at a time is three round trips to learn one fact.
 */
export function TestSendModal({
    open,
    onClose,
    onSend,
    sending,
    channel,
    account,
    templates,
    title,
    label,
    placeholder,
    hint,
    type = 'tel',
}: {
    open: boolean;
    onClose: () => void;
    onSend: (payload: { recipients: string[]; templateId: number | null }) => Promise<unknown>;
    sending?: boolean;
    channel: Channel;
    account: ChannelAccount;
    templates: MessageTemplate[];
    title: string;
    label: string;
    placeholder: string;
    hint: string;
    type?: 'tel' | 'email';
}) {
    const inputRef = useRef<HTMLInputElement | null>(null);

    const [recipients, setRecipients] = useState<string[]>([]);
    const [draft, setDraft] = useState('');
    const [templateId, setTemplateId] = useState('');
    const [sent, setSent] = useState<string[] | null>(null);
    const [failed, setFailed] = useState<string | null>(null);

    // Each opening starts fresh, seeded with the account's own address — the
    // commonest test is "does it come back to me".
    useEffect(() => {
        if (open) {
            setRecipients(placeholder ? [placeholder] : []);
            setDraft('');
            setTemplateId('');
            setSent(null);
            setFailed(null);
        }
    }, [open, placeholder]);

    /** Adds whatever is in `text`, split, trimmed and deduplicated. */
    function add(text: string): string[] {
        const parts = text.split(SEPARATORS).map((part) => part.trim()).filter(Boolean);

        if (parts.length === 0) {
            return recipients;
        }

        const next = [...recipients];

        for (const part of parts) {
            if (next.length >= MAX) {
                setFailed(`A test goes to at most ${MAX} addresses.`);
                break;
            }

            // Case-insensitive: two spellings of one email address are one
            // address, and the server refuses the pair as duplicates.
            if (!next.some((item) => item.toLowerCase() === part.toLowerCase())) {
                next.push(part);
            }
        }

        setRecipients(next);
        setDraft('');

        return next;
    }

    function remove(target: string) {
        setRecipients((current) => current.filter((item) => item !== target));
        setFailed(null);
    }

    function onKeyDown(event: KeyboardEvent<HTMLInputElement>) {
        if (event.key === 'Enter' || event.key === ',' || event.key === ';') {
            event.preventDefault();
            add(draft);

            return;
        }

        // Backspace on an empty box takes back the last chip, which is what
        // every address field does and what fingers expect.
        if (event.key === 'Backspace' && draft === '' && recipients.length > 0) {
            event.preventDefault();
            remove(recipients[recipients.length - 1]);
        }
    }

    function onPaste(event: ClipboardEvent<HTMLInputElement>) {
        const text = event.clipboardData.getData('text');

        if (SEPARATORS.test(text)) {
            event.preventDefault();
            add(text);
        }
    }

    async function send() {
        setFailed(null);

        // Commit whatever was typed but never entered, or it is lost silently.
        const list = draft.trim() === '' ? recipients : add(draft);

        if (list.length === 0) {
            setFailed('Add at least one address to send the test to.');

            return;
        }

        try {
            await onSend({
                recipients: list,
                templateId: templateId === '' ? null : Number(templateId),
            });

            setSent(list);
        } catch {
            // The hook raises a toast; the dialog stays open so the list can
            // be corrected rather than retyped.
            setFailed('That did not go through. Check the addresses and try again.');
        }
    }

    const sendable = templates.filter((template) => template.status === 'approved');
    const chosen = sendable.find((template) => String(template.id) === templateId) ?? null;

    const mark = channel === 'email' ? 'ti ti-mail' : 'ti ti-brand-whatsapp';
    const tone = channel === 'email' ? 'is-sky' : 'is-emerald';
    const total = recipients.length + (draft.trim() === '' ? 0 : 1);

    return (
        <Modal
            open={open}
            title={title}
            subtitle="Checks the connection end to end"
            onClose={onClose}
            size="lg"
            busy={sending}
            icon={<i className="ti ti-send" />}
            footer={
                <>
                    <Button variant="light" onClick={onClose} disabled={sending}>
                        {sent ? 'Done' : 'Cancel'}
                    </Button>

                    <Button
                        icon={sent ? 'ti ti-check' : 'ti ti-send'}
                        loading={sending}
                        disabled={sent !== null || total === 0}
                        onClick={() => void send()}
                    >
                        {sent ? 'Sent' : total > 1 ? `Send ${total} tests` : 'Send test'}
                    </Button>
                </>
            }
        >
            <div className="comm-test">
                <div className="comm-test-form">
                    {/* From, then to — the order somebody checks when a test
                        does not arrive, and the from is the half people
                        forget they never finished setting up. */}
                    <div className="comm-test-route">
                        <div className="comm-test-end">
                            <span className={`comm-mark ${tone}`} aria-hidden="true">
                                <i className={mark} />
                            </span>

                            <div>
                                <small>From</small>
                                <b>{account.display_name ?? 'This clinic'}</b>
                                <span>{account.handle ?? 'No account connected'}</span>
                            </div>
                        </div>

                        <i className="ti ti-arrow-right comm-test-arrow" aria-hidden="true" />

                        <div className="comm-test-end">
                            <span className="comm-mark is-muted" aria-hidden="true">
                                <i className={total > 1 ? 'ti ti-users' : 'ti ti-user'} />
                            </span>

                            <div>
                                <small>To</small>

                                <b>
                                    {total === 0
                                        ? 'Nobody yet'
                                        : total === 1
                                          ? (recipients[0] ?? draft.trim())
                                          : `${total} recipients`}
                                </b>

                                <span>
                                    {channel === 'email' ? 'Email addresses' : 'WhatsApp numbers'}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div>
                        <label className="form-label comm-field-label" htmlFor="comm-test-target">
                            <span>{label}</span>

                            <span className="comm-test-count">
                                {recipients.length}/{MAX}
                            </span>
                        </label>

                        {/* One control made of chips and an input: clicking
                            anywhere focuses the field, as an address bar does. */}
                        <div
                            className={`comm-chips${sent ? ' is-disabled' : ''}`}
                            onClick={() => inputRef.current?.focus()}
                        >
                            {recipients.map((recipient) => (
                                <span key={recipient} className="comm-chip">
                                    {recipient}

                                    {!sent && (
                                        <button
                                            type="button"
                                            aria-label={`Remove ${recipient}`}
                                            onClick={(event) => {
                                                event.stopPropagation();
                                                remove(recipient);
                                            }}
                                        >
                                            <i className="ti ti-x" />
                                        </button>
                                    )}
                                </span>
                            ))}

                            {!sent && recipients.length < MAX && (
                                <input
                                    ref={inputRef}
                                    id="comm-test-target"
                                    type={type}
                                    value={draft}
                                    disabled={sending}
                                    placeholder={
                                        recipients.length === 0 ? placeholder : 'Add another…'
                                    }
                                    onChange={(event) => setDraft(event.target.value)}
                                    onKeyDown={onKeyDown}
                                    onPaste={onPaste}
                                    onBlur={() => add(draft)}
                                />
                            )}
                        </div>

                        <small className="text-muted d-block mt-1">
                            {hint} Press Enter or comma between addresses.
                        </small>
                    </div>

                    {/*
                        Which template to test with.

                        The real question is rarely "does the connection work"
                        — it is "does the appointment reminder look right on a
                        real phone", and only sending that one answers it.
                    */}
                    <div>
                        <label className="form-label" htmlFor="comm-test-template">
                            Send which message
                        </label>

                        <select
                            id="comm-test-template"
                            className="form-select"
                            value={templateId}
                            disabled={sent !== null || sending}
                            onChange={(event) => setTemplateId(event.target.value)}
                        >
                            <option value="">A plain test message</option>

                            {sendable.map((template) => (
                                <option key={template.id} value={String(template.id)}>
                                    {template.name}
                                </option>
                            ))}
                        </select>

                        <small className="text-muted d-block mt-1">
                            {sendable.length === 0
                                ? 'No approved templates yet, so only a plain test can be sent.'
                                : 'Only approved templates can be sent.'}
                        </small>
                    </div>
                </div>

                {/* What lands. The whole point of a test, and the half the
                    dialog used only to describe in words. */}
                <div className="comm-test-preview">
                    <span className="comm-subhead">What they will get</span>

                    {/* Channel-shaped. An email previewed as a chat bubble
                        shows a green WhatsApp header and no subject line —
                        which is the one part of an email that always arrives. */}
                    <TemplatePreview
                        channel={channel}
                        name={chosen?.name ?? account.display_name ?? 'This clinic'}
                        subject={chosen?.subject ?? 'Test message'}
                        content={chosen?.content ?? PLAIN}
                        icon={chosen?.icon}
                    />

                    <p className="pf-soon">
                        <i className="ti ti-flask" aria-hidden="true" />
                        Marked as a test, so it never counts towards your delivery rates.
                    </p>

                    {failed && (
                        <div className="comm-test-result is-fail">
                            <i className="ti ti-alert-triangle" aria-hidden="true" />
                            {failed}
                        </div>
                    )}

                    {sent && (
                        <div className="comm-test-result is-ok">
                            <i className="ti ti-circle-check" aria-hidden="true" />

                            <div>
                                <b>
                                    Queued for {sent.length}{' '}
                                    {sent.length === 1 ? 'address' : 'addresses'}
                                </b>

                                <span>
                                    {sent.join(', ')} — they appear in the delivery log either way.
                                </span>
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </Modal>
    );
}
