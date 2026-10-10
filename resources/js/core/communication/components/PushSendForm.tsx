import { useEffect, useState, type FormEvent } from 'react';
import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import { getValidationErrors, resolveErrorMessage } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { usePushRecipients, useSendPush } from '../api';
import { fillPlaceholders } from '../placeholders';
import type { MessageTemplate } from '../types';

/**
 * One notification, typed here, to one person's phones.
 *
 * Only people with a registered phone are offered — anybody else has nowhere
 * to receive it. With the channel switched off it still goes to the sender's
 * own phone, which is how somebody checks it works before switching it on;
 * the server holds that line, this only says so up front.
 */
export function PushSendForm({ on, templates = [] }: { on: boolean; templates?: MessageTemplate[] }) {
    const { organization } = useTenantAuth();
    const { data: people = [], isLoading } = usePushRecipients();
    const send = useSendPush();

    const [userId, setUserId] = useState<number | ''>('');
    const [templateId, setTemplateId] = useState<number | ''>('');
    const [title, setTitle] = useState('Test notification');
    const [body, setBody] = useState('');
    const [errors, setErrors] = useState<Record<string, string[]>>({});

    /**
     * Fills Title and Message from the chosen template, with sample values —
     * the same ones its own preview uses — standing in for a real patient's
     * details. Still plain fields afterwards: picking one is a starting
     * point, not a lock, so the wording can be trimmed before it sends.
     */
    function applyTemplate(id: number | '') {
        setTemplateId(id);

        const template = templates.find((item) => item.id === id);

        if (template) {
            setTitle(fillPlaceholders(template.subject ?? template.name));
            setBody(fillPlaceholders(template.content));
        }
    }

    useEffect(() => {
        setBody((current) => current || `This is a test notification from ${organization?.name ?? 'your clinic'}.`);
    }, [organization?.name]);

    const me = people.find((person) => person.is_me);
    const chosen = people.find((person) => person.id === userId);
    const blocked = !on && chosen !== undefined && !chosen.is_me;

    async function submit(event: FormEvent) {
        event.preventDefault();
        setErrors({});

        if (userId === '') {
            setErrors({ user_id: ['Choose who to send it to.'] });

            return;
        }

        try {
            await send.mutateAsync({ user_id: userId, title: title.trim(), body: body.trim() });
        } catch (error) {
            const found = getValidationErrors(error);

            if (found) {
                setErrors(found);
            } else {
                notify.error(resolveErrorMessage(error));
            }
        }
    }

    return (
        <Card
            className="ph-card"
            title="Send test notification"
            icon="ti ti-send-2"
            description="Send a test push notification to a specific user or device"
        >
            <form className="ph-form" onSubmit={(event) => void submit(event)} noValidate>
                {templates.length > 0 && (
                    <div className="ph-field">
                        <label className="form-label" htmlFor="ph-send-template">
                            Use a template
                        </label>
                        <select
                            id="ph-send-template"
                            className="form-select"
                            value={templateId}
                            onChange={(event) =>
                                applyTemplate(event.target.value === '' ? '' : Number(event.target.value))
                            }
                        >
                            <option value="">Write it myself</option>
                            {templates.map((template) => (
                                <option key={template.id} value={template.id}>
                                    {template.name}
                                </option>
                            ))}
                        </select>
                    </div>
                )}

                <div className="ph-form-row">
                    <div className="ph-field is-grow">
                        <label className="form-label" htmlFor="ph-send-to">
                            Send to <span className="text-danger">*</span>
                        </label>

                        <div className="ph-pick">
                            <select
                                id="ph-send-to"
                                className={`form-select${errors.user_id ? ' is-invalid' : ''}`}
                                value={userId}
                                disabled={isLoading}
                                onChange={(event) => setUserId(event.target.value === '' ? '' : Number(event.target.value))}
                            >
                                <option value="">
                                    {isLoading
                                        ? 'Loading…'
                                        : people.length === 0
                                          ? 'Nobody has a registered phone yet'
                                          : 'Select user / device'}
                                </option>

                                {people.map((person) => (
                                    <option key={person.id} value={person.id}>
                                        {person.name}
                                        {person.is_me ? ' (you)' : ''} · {person.code ?? person.role ?? person.kind} ·{' '}
                                        {person.devices} {person.devices === 1 ? 'phone' : 'phones'}
                                    </option>
                                ))}
                            </select>

                            <button
                                type="button"
                                className="ph-icon-btn is-boxed is-lg"
                                title={me ? 'Send to my own phone' : 'Your phone is not registered — sign in to the app first'}
                                aria-label="Send to my own phone"
                                disabled={!me}
                                onClick={() => me && setUserId(me.id)}
                            >
                                <i className="ti ti-user-check" />
                            </button>
                        </div>

                        {errors.user_id && <div className="invalid-feedback d-block">{errors.user_id[0]}</div>}
                    </div>

                    <div className="ph-field is-grow">
                        <label className="form-label" htmlFor="ph-send-title">
                            Title <span className="text-danger">*</span>
                        </label>
                        <input
                            id="ph-send-title"
                            className={`form-control${errors.title ? ' is-invalid' : ''}`}
                            value={title}
                            maxLength={120}
                            onChange={(event) => setTitle(event.target.value)}
                        />
                        {errors.title && <div className="invalid-feedback d-block">{errors.title[0]}</div>}
                    </div>
                </div>

                <div className="ph-field">
                    <label className="form-label" htmlFor="ph-send-body">
                        Message <span className="text-danger">*</span>
                    </label>
                    <textarea
                        id="ph-send-body"
                        className={`form-control${errors.body ? ' is-invalid' : ''}`}
                        rows={3}
                        maxLength={500}
                        value={body}
                        onChange={(event) => setBody(event.target.value)}
                    />
                    <div className="ph-form-meta">
                        {errors.body ? (
                            <span className="text-danger">{errors.body[0]}</span>
                        ) : blocked ? (
                            <span className="ph-warn">
                                <i className="ti ti-info-circle" aria-hidden="true" /> Switch App notifications on to
                                notify anybody but yourself.
                            </span>
                        ) : (
                            <span>Logged as a test — it does not count in the figures.</span>
                        )}
                        <span>{body.length}/500</span>
                    </div>
                </div>

                <div className="ph-form-actions">
                    <Button type="submit" icon="ti ti-send" loading={send.isPending} disabled={blocked}>
                        Send test notification
                    </Button>
                </div>
            </form>
        </Card>
    );
}
