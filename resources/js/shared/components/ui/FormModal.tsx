import { useId, type FormEventHandler, type ReactNode } from 'react';
import { Modal, type ModalSize } from './Modal';
import { Button } from './Button';
import { LoadingBlock } from './Feedback';

interface FormModalProps {
    open: boolean;
    onClose: () => void;
    title: ReactNode;
    subtitle?: ReactNode;
    size?: ModalSize;
    icon?: ReactNode;
    /** Usually react-hook-form's handleSubmit(...) result. */
    onSubmit: FormEventHandler<HTMLFormElement>;
    submitting?: boolean;
    /**
     * The record being edited is still being fetched. The body shows a
     * loader instead of a form that would otherwise flash empty fields.
     */
    loading?: boolean;
    submitLabel?: string;
    cancelLabel?: string;
    /** Use "danger" for destructive confirmations that still need a form. */
    submitVariant?: 'primary' | 'danger';
    children: ReactNode;
}

const SAVE_ICON = (
    <svg
        xmlns="http://www.w3.org/2000/svg"
        width="17"
        height="17"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth="2"
        strokeLinecap="round"
        strokeLinejoin="round"
        className="me-1"
    >
        <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z" />
        <polyline points="17 21 17 13 7 13 7 21" />
        <polyline points="7 3 7 8 15 8" />
    </svg>
);

/**
 * A modal that contains a form, with the standard Cancel / Save footer.
 *
 * The submit button sits in the footer but is bound to the form through the
 * HTML `form` attribute — the same trick the Blade `components.modal`
 * partial used with its `formId` prop. That keeps Enter-to-submit working
 * without the button having to live inside the form element.
 *
 * Feature pages therefore only supply fields and a submit handler; sizing,
 * spacing, button order, labels and busy states stay identical everywhere.
 */
export function FormModal({
    open,
    onClose,
    title,
    subtitle,
    size = 'lg',
    icon,
    onSubmit,
    submitting = false,
    loading = false,
    submitLabel = 'Save',
    cancelLabel = 'Cancel',
    submitVariant = 'primary',
    children,
}: FormModalProps) {
    const formId = useId();

    return (
        <Modal
            open={open}
            onClose={onClose}
            title={title}
            subtitle={subtitle}
            size={size}
            icon={icon}
            busy={submitting}
            footer={
                <>
                    <Button variant="light" onClick={onClose} disabled={submitting}>
                        {cancelLabel}
                    </Button>

                    <Button
                        type="submit"
                        form={formId}
                        variant={submitVariant}
                        loading={submitting}
                        // Nothing to save until the record has arrived.
                        disabled={loading}
                    >
                        {!submitting && SAVE_ICON}
                        {submitLabel}
                    </Button>
                </>
            }
        >
            {loading && (
                <div className="form-modal-loading">
                    <LoadingBlock label="Loading…" />
                </div>
            )}

            {/* The form stays mounted while loading so react-hook-form keeps
                its registrations — it is just hidden behind the loader. */}
            <form id={formId} onSubmit={onSubmit} noValidate hidden={loading}>
                {children}
            </form>
        </Modal>
    );
}
