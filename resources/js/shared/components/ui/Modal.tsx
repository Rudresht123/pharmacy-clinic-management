import { useEffect, useRef, useState, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { cn } from '@/shared/utils/cn';

/** The only sizes the app uses — keeps dialogs consistent across features. */
export type ModalSize = 'sm' | 'md' | 'lg' | 'xl';

interface ModalProps {
    open: boolean;
    title: ReactNode;
    subtitle?: ReactNode;
    onClose: () => void;
    children: ReactNode;
    footer?: ReactNode;
    size?: ModalSize;
    /** SVG shown in the header icon box. Defaults to a plus mark. */
    icon?: ReactNode;
    /** Tints the icon box red — used for destructive dialogs. */
    tone?: 'primary' | 'danger';
    /** Block dismissal while a request is in flight. */
    busy?: boolean;
}

const SIZES: Record<ModalSize, string> = {
    sm: 'modal-sm',
    md: 'modal-md',
    lg: 'modal-lg',
    xl: 'modal-xl',
};

/** Matches Bootstrap's .modal.fade transition duration. */
const EXIT_MS = 300;

const DEFAULT_ICON = (
    <svg
        xmlns="http://www.w3.org/2000/svg"
        width="20"
        height="20"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth="2"
    >
        <path d="M12 5v14" />
        <path d="M5 12h14" />
    </svg>
);

/**
 * React port of the Blade `components.modal` partial.
 *
 * Markup and classes are the theme's own — .modal-icon-box,
 * .custom-close-btn, bg-light header/footer — so dialogs look exactly like
 * the ones the Blade app shipped. Bootstrap's modal JS is not used; React
 * toggles .show and the fade comes from Bootstrap's own CSS.
 */
export function Modal({
    open,
    title,
    subtitle,
    onClose,
    children,
    footer,
    size = 'md',
    icon,
    tone = 'primary',
    busy,
}: ModalProps) {
    // `mounted` keeps the dialog in the DOM through its exit transition;
    // `visible` toggles Bootstrap's .show class.
    const [mounted, setMounted] = useState(open);
    const [visible, setVisible] = useState(false);
    const dialogRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (open) {
            setMounted(true);

            // Paint once without .show so the fade has a starting point.
            const frame = requestAnimationFrame(() => setVisible(true));

            return () => cancelAnimationFrame(frame);
        }

        setVisible(false);

        const timer = window.setTimeout(() => setMounted(false), EXIT_MS);

        return () => window.clearTimeout(timer);
    }, [open]);

    // Escape to dismiss, and hold the page still behind the dialog.
    useEffect(() => {
        if (!mounted) {
            return;
        }

        function onKeyDown(event: KeyboardEvent) {
            if (event.key === 'Escape' && !busy) {
                onClose();
            }
        }

        const previousOverflow = document.body.style.overflow;

        document.addEventListener('keydown', onKeyDown);
        document.body.style.overflow = 'hidden';

        return () => {
            document.removeEventListener('keydown', onKeyDown);
            document.body.style.overflow = previousOverflow;
        };
    }, [mounted, onClose, busy]);

    // Move focus into the dialog for keyboard and screen-reader users.
    useEffect(() => {
        if (visible) {
            dialogRef.current?.focus();
        }
    }, [visible]);

    if (!mounted) {
        return null;
    }

    return createPortal(
        <>
            <div
                className={cn('modal fade', visible && 'show')}
                role="dialog"
                aria-modal="true"
                // Centring is set here rather than left to Bootstrap's
                // .modal-dialog-centered so no theme rule can shift it.
                style={{
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    overflowY: 'auto',
                    padding: '1rem',
                }}
                onMouseDown={(event) => {
                    // Only a press that starts on the backdrop should close.
                    if (event.target === event.currentTarget && !busy) {
                        onClose();
                    }
                }}
            >
                <div
                    ref={dialogRef}
                    className={cn('modal-dialog', SIZES[size])}
                    style={{ margin: 0, width: '100%' }}
                    tabIndex={-1}
                >
                    <div
                        className="modal-content border-0 shadow-sm"
                        // Keep tall forms scrollable inside the dialog rather
                        // than pushing it off screen.
                        style={{ maxHeight: 'calc(100vh - 2rem)' }}
                    >
                        <div className="modal-header bg-light border-bottom position-relative">
                            <button
                                type="button"
                                className="custom-close-btn"
                                aria-label="Close"
                                disabled={busy}
                                onClick={onClose}
                            >
                                <svg
                                    xmlns="http://www.w3.org/2000/svg"
                                    width="18"
                                    height="18"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    strokeWidth="2"
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                >
                                    <path d="M18 6L6 18" />
                                    <path d="M6 6l12 12" />
                                </svg>
                            </button>

                            <div className="d-flex align-items-center">
                                <div className="me-2">
                                    <div
                                        className="modal-icon-box"
                                        style={
                                            tone === 'danger'
                                                ? {
                                                      background: 'rgba(220, 53, 69, .10)',
                                                      color: '#dc3545',
                                                  }
                                                : undefined
                                        }
                                    >
                                        {icon ?? DEFAULT_ICON}
                                    </div>
                                </div>

                                <div>
                                    <h6 className="modal-title fw-semibold mb-0">{title}</h6>

                                    {subtitle && (
                                        <div className="text-muted modal-subtitle">{subtitle}</div>
                                    )}
                                </div>
                            </div>
                        </div>

                        <div
                            className="modal-body px-3 px-sm-4 py-3 py-sm-4"
                            style={{ overflowY: 'auto' }}
                        >
                            {children}
                        </div>

                        {footer && <div className="modal-footer bg-light border-top">{footer}</div>}
                    </div>
                </div>
            </div>

            <div className={cn('modal-backdrop fade', visible && 'show')} />
        </>,
        document.body,
    );
}
