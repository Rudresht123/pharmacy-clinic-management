import { Fragment, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import type { Tone } from './tones';

export interface Crumb {
    label: string;
    to?: string;
}

interface PageHeaderProps {
    title: string;
    subtitle?: string;
    crumbs?: Crumb[];
    actions?: ReactNode;
    /** Tabler icon class giving the page its identity, e.g. "ti ti-building-store". */
    icon?: string;
    /** Accent colour for the icon and the header's corner glow. */
    tone?: Tone;
    /** Set false to drop the leading dashboard link. */
    home?: boolean;
}

/**
 * Page title, crumb trail and page-level actions.
 *
 * Replaces the Blade `components.brudcrumb` partial, which rendered only a
 * title and subtitle — the `page` value its controllers passed was never
 * displayed. Styling lives in vendor/css/layout.css.
 */
export function PageHeader({
    title,
    subtitle,
    crumbs = [],
    actions,
    icon,
    tone = 'indigo',
    home = true,
}: PageHeaderProps) {
    const showTrail = home || crumbs.length > 0;

    return (
        // The tone is set here so both the icon and the corner glow inherit it.
        <div className="page-head" data-tone={tone}>
            {icon && (
                <span className="page-head-icon" aria-hidden="true">
                    <i className={icon} />
                </span>
            )}

            <div className="page-head-body">
                {/*
                    Title first, then the trail, then the line explaining the
                    page. The heading is what the screen is called, so it leads;
                    the trail is context for it and sits underneath, close
                    enough that the icon beside them covers both.
                */}
                <h1>{title}</h1>

                {showTrail && (
                    <nav className="page-crumbs" aria-label="Breadcrumb">
                        {home && (
                            <>
                                <Link to="/dashboard" className="crumb-home" title="Dashboard">
                                    <i className="ti ti-home" />
                                </Link>

                                {crumbs.length > 0 && <span className="crumb-sep">›</span>}
                            </>
                        )}

                        {crumbs.map((crumb, index) => {
                            const isLast = index === crumbs.length - 1;

                            return (
                                <Fragment key={`${crumb.label}-${index}`}>
                                    {crumb.to && !isLast ? (
                                        <Link to={crumb.to}>{crumb.label}</Link>
                                    ) : (
                                        <span className={isLast ? 'crumb-current' : undefined}>
                                            {crumb.label}
                                        </span>
                                    )}

                                    {!isLast && <span className="crumb-sep">›</span>}
                                </Fragment>
                            );
                        })}
                    </nav>
                )}

                {subtitle && <p className="page-lead">{subtitle}</p>}
            </div>

            {actions && <div className="page-head-actions">{actions}</div>}
        </div>
    );
}
