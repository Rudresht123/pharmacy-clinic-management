import { useEffect, useState } from 'react';
import { FormModal } from '@/shared/components/ui/FormModal';

interface ReasonDialogProps {
    open: boolean;
    title: string;
    subtitle?: string;
    submitLabel: string;
    danger?: boolean;
    submitting?: boolean;
    /** The server's answer when it refused, shown under the box. */
    error?: string | null;
    onClose: () => void;
    onSubmit: (reason: string) => void;
}

/**
 * Asks why, before removing or restoring something.
 *
 * The reason is required by the server and kept in the record's history, so
 * the box is required here too rather than a confirm with an optional note.
 */
export function ReasonDialog({
    open,
    title,
    subtitle,
    submitLabel,
    danger,
    submitting,
    error,
    onClose,
    onSubmit,
}: ReasonDialogProps) {
    const [reason, setReason] = useState('');

    // Each opening starts blank; a reason belongs to one decision.
    useEffect(() => {
        if (open) {
            setReason('');
        }
    }, [open]);

    return (
        <FormModal
            open={open}
            onClose={onClose}
            title={title}
            subtitle={subtitle}
            submitLabel={submitLabel}
            submitVariant={danger ? 'danger' : 'primary'}
            submitting={submitting}
            onSubmit={(event) => {
                event.preventDefault();

                if (reason.trim() !== '') {
                    onSubmit(reason.trim());
                }
            }}
        >
            <label className="form-label" htmlFor="reason-input">
                Reason <span className="req">*</span>
            </label>

            <textarea
                id="reason-input"
                className={`form-control${error ? ' is-invalid' : ''}`}
                rows={3}
                maxLength={500}
                required
                autoFocus
                value={reason}
                onChange={(event) => setReason(event.target.value)}
                placeholder="Entered twice by mistake"
            />

            {error ? (
                <div className="invalid-feedback d-block">{error}</div>
            ) : (
                <small className="form-hint">Kept in this medicine&rsquo;s history.</small>
            )}
        </FormModal>
    );
}
