import toast, { type Toast } from 'react-hot-toast';
import type { Tone } from '@/shared/components/ui/tones';

type Variant = 'success' | 'error' | 'warning' | 'info';

interface VariantStyle {
    tone: Tone;
    icon: string;
    fallbackTitle: string;
    duration: number;
}

const VARIANTS: Record<Variant, VariantStyle> = {
    success: {
        tone: 'emerald',
        icon: 'ti ti-circle-check',
        fallbackTitle: 'Done',
        duration: 4000,
    },
    error: {
        tone: 'rose',
        icon: 'ti ti-alert-circle',
        fallbackTitle: 'Something went wrong',
        // Failures need longer — the reader may have to act on them.
        duration: 6000,
    },
    warning: {
        tone: 'amber',
        icon: 'ti ti-alert-triangle',
        fallbackTitle: 'Heads up',
        duration: 5000,
    },
    info: {
        tone: 'sky',
        icon: 'ti ti-info-circle',
        fallbackTitle: 'Note',
        duration: 4000,
    },
};

interface Options {
    /** Secondary line under the title. */
    description?: string;
    /** Milliseconds on screen; defaults per variant. */
    duration?: number;
    /** Reuse an id to replace an existing toast instead of stacking another. */
    id?: string;
}

function render(variant: Variant, title: string, options: Options = {}) {
    const style = VARIANTS[variant];
    const duration = options.duration ?? style.duration;

    return toast.custom(
        (t: Toast) => (
            <div
                className="toast-card"
                data-tone={style.tone}
                data-visible={t.visible}
                role="status"
            >
                <span className="toast-icon" aria-hidden="true">
                    <i className={style.icon} />
                </span>

                <div className="toast-body">
                    <p className="toast-title">{title || style.fallbackTitle}</p>
                    {options.description && <p className="toast-text">{options.description}</p>}
                </div>

                <button
                    type="button"
                    className="toast-close"
                    aria-label="Dismiss"
                    onClick={() => toast.dismiss(t.id)}
                >
                    <i className="ti ti-x" />
                </button>

                <span
                    className="toast-progress"
                    style={{ animationDuration: `${duration}ms` }}
                    aria-hidden="true"
                />
            </div>
        ),
        { duration, id: options.id },
    );
}

/**
 * App-wide notifications.
 *
 * Wraps react-hot-toast so every message shares one look: a tone-coloured
 * icon, an optional second line, a dismiss button and a bar that drains for
 * the toast's lifetime (and pauses while hovered).
 *
 *   notify.success('Organization created', { description: org.name })
 */
export const notify = {
    success: (title: string, options?: Options) => render('success', title, options),
    error: (title: string, options?: Options) => render('error', title, options),
    warning: (title: string, options?: Options) => render('warning', title, options),
    info: (title: string, options?: Options) => render('info', title, options),
    dismiss: (id?: string) => toast.dismiss(id),
};
