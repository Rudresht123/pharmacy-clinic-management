import { useState, type ReactNode } from 'react';
import { Modal } from '@/shared/components/ui/Modal';
import { Button } from '@/shared/components/ui/Button';
import { getValidationErrors, resolveErrorMessage } from '@/shared/api/http';
import { STATUS_TONE } from '../labels';

export function StatusChip({ status, label }: { status: string; label?: string }) {
    return <span className={`sx-chip sx-chip--${STATUS_TONE[status] ?? 'muted'}`}>{label ?? status.replace('_', ' ')}</span>;
}

export function FieldError({ errors, name }: { errors: Record<string, string[]>; name: string }) {
    const message = errors[name]?.[0];

    return message ? <div className="invalid-feedback d-block">{message}</div> : null;
}

/**
 * One administrator action on billing — a form in a dialog that always asks
 * WHY, because every lever is written to the audit trail with its reason.
 *
 * `submit` receives the reason (when asked for) and throws the API error; a
 * 422 lands under the fields it names, anything else on the dialog itself.
 */
export function ActionDialog({
    title,
    subtitle,
    icon = 'ti ti-adjustments',
    tone = 'primary',
    submitLabel = 'Save',
    askReason = true,
    reasonHint,
    children,
    onClose,
    submit,
}: {
    title: string;
    subtitle?: string;
    icon?: string;
    tone?: 'primary' | 'danger';
    submitLabel?: string;
    askReason?: boolean;
    reasonHint?: string;
    children?: (errors: Record<string, string[]>) => ReactNode;
    onClose: () => void;
    submit: (reason: string) => Promise<unknown>;
}) {
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [failure, setFailure] = useState<string | null>(null);

    async function go() {
        setBusy(true);
        setErrors({});
        setFailure(null);

        try {
            await submit(reason.trim());
            onClose();
        } catch (error) {
            const validation = getValidationErrors(error);

            if (validation) {
                setErrors(validation);
            } else {
                setFailure(resolveErrorMessage(error));
            }
        } finally {
            setBusy(false);
        }
    }

    return (
        <Modal
            open
            title={title}
            subtitle={subtitle}
            icon={<i className={icon} />}
            tone={tone}
            busy={busy}
            onClose={onClose}
            footer={
                <>
                    <Button variant="light" onClick={onClose} disabled={busy}>
                        Cancel
                    </Button>
                    <Button variant={tone === 'danger' ? 'danger' : 'primary'} loading={busy} onClick={() => void go()}>
                        {submitLabel}
                    </Button>
                </>
            }
        >
            {children?.(errors)}

            {askReason && (
                <div className="mb-2">
                    <label className="form-label" htmlFor="action-reason">
                        Reason <span className="text-danger">*</span>
                    </label>
                    <textarea
                        id="action-reason"
                        className={`form-control ${errors.reason ? 'is-invalid' : ''}`}
                        rows={2}
                        value={reason}
                        placeholder="Why — this is recorded with your name on the subscription's history."
                        onChange={(e) => setReason(e.target.value)}
                    />
                    <FieldError errors={errors} name="reason" />
                    {reasonHint && !errors.reason && <small className="text-muted">{reasonHint}</small>}
                </div>
            )}

            {failure && <div className="alert alert-danger py-2 mb-0">{failure}</div>}
        </Modal>
    );
}
