import type { ReactNode } from 'react';
import { Card } from '@/shared/components/ui/Card';

/** One part of the test page: icon, title, a line on what it decides. */
export function FormSection({
    icon,
    title,
    caption,
    actions,
    children,
}: {
    icon: string;
    title: string;
    caption: string;
    actions?: ReactNode;
    children: ReactNode;
}) {
    return (
        <Card className="lab-sec">
            <div className="lab-sec-head">
                <span className="lab-sec-icon" aria-hidden="true">
                    <i className={icon} />
                </span>
                <div className="lab-sec-title">
                    <h6>{title}</h6>
                    <p>{caption}</p>
                </div>
                {actions && <div className="lab-sec-actions">{actions}</div>}
            </div>
            {children}
        </Card>
    );
}

/** A labelled control with its error, in the page's own compact style. */
export function Field({
    label,
    required,
    error,
    hint,
    className,
    children,
}: {
    label: string;
    required?: boolean;
    error?: string;
    hint?: string;
    className?: string;
    children: ReactNode;
}) {
    return (
        <label className={`lab-field ${className ?? ''}`}>
            <span className="lab-field-label">
                {label}
                {required && <span className="text-danger ms-1">*</span>}
            </span>
            {children}
            {error ? <span className="lab-field-error">{error}</span> : hint && <span className="lab-field-hint">{hint}</span>}
        </label>
    );
}
