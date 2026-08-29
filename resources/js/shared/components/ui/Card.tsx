import type { ReactNode } from 'react';
import { cn } from '@/shared/utils/cn';

interface CardProps {
    title?: ReactNode;
    /** Tabler icon class, e.g. "ti ti-building-store". Shown beside the title. */
    icon?: string;
    /** One line under the title saying what the section is for. */
    description?: ReactNode;
    actions?: ReactNode;
    children: ReactNode;
    className?: string;
    bodyClassName?: string;
}

/**
 * A titled panel.
 *
 * The icon and description turn a long form into something scannable — with
 * only a bold title, three stacked cards read as one undifferentiated wall of
 * inputs.
 */
export function Card({
    title,
    icon,
    description,
    actions,
    children,
    className,
    bodyClassName,
}: CardProps) {
    const hasHeader = Boolean(title || actions);

    return (
        <div className={cn('card', className)}>
            {hasHeader && (
                <div className="card-header">
                    <div className="card-heading">
                        {icon && (
                            <span className="card-icon" aria-hidden="true">
                                <i className={icon} />
                            </span>
                        )}

                        <div className="card-heading-text">
                            {title && <h5 className="card-title mb-0">{title}</h5>}
                            {description && <p className="card-description">{description}</p>}
                        </div>
                    </div>

                    {actions}
                </div>
            )}

            <div className={cn('card-body', bodyClassName)}>{children}</div>
        </div>
    );
}
