import type { ReactNode } from 'react';
import { cn } from '@/shared/utils/cn';
import type { Tone } from './tones';

/** Small inline spinner for buttons and table cells. */
export function Spinner({ className }: { className?: string }) {
    return (
        <span
            className={cn('spinner-border spinner-border-sm text-primary', className)}
            role="status"
            aria-label="Loading"
        />
    );
}

/**
 * Section-level loading state — a table refreshing, a form fetching its
 * record. Deliberately a plain spinner: the full-screen branded loader is
 * only for the initial page load, never inside a card.
 */
export function LoadingBlock({ label = 'Loading…' }: { label?: string }) {
    return (
        <div className="d-flex flex-column align-items-center justify-content-center py-5 text-muted">
            <Spinner className="mb-2" />
            <span className="fs-13">{label}</span>
        </div>
    );
}

interface StateProps {
    icon?: string;
    tone?: Tone;
    title: string;
    description?: ReactNode;
    actions?: ReactNode;
}

/**
 * Shared shell for empty, no-result and error states: an icon medallion,
 * a heading, a line of guidance and optional actions.
 *
 * Styling lives in vendor/css/layout.css (.state*).
 */
function State({ icon = 'ti ti-inbox', tone = 'indigo', title, description, actions }: StateProps) {
    return (
        // Tone sits on the wrapper so the medallion, its halo and the action
        // buttons all derive from the same colour.
        <div className="state" data-tone={tone}>
            <div className="state-art" aria-hidden="true">
                <i className={icon} />
            </div>

            <h5>{title}</h5>

            {description && <p>{description}</p>}

            {actions && <div className="state-actions">{actions}</div>}
        </div>
    );
}

interface EmptyStateProps {
    icon?: string;
    tone?: Tone;
    title: string;
    description?: ReactNode;
    action?: ReactNode;
}

/** Nothing exists yet — the moment to invite the first record. */
export function EmptyState({
    icon = 'ti ti-folder-open',
    tone = 'indigo',
    title,
    description,
    action,
}: EmptyStateProps) {
    return (
        <State icon={icon} tone={tone} title={title} description={description} actions={action} />
    );
}

interface NoResultsStateProps {
    term: string;
    onClear?: () => void;
    /** Defaults to the page's own accent so the whole screen stays on-tone. */
    tone?: Tone;
}

/** A search or filter matched nothing. Distinct from "nothing exists". */
export function NoResultsState({ term, onClear, tone = 'indigo' }: NoResultsStateProps) {
    return (
        <State
            icon="ti ti-search-off"
            tone={tone}
            title="No matching results"
            description={
                <>
                    Nothing matched <span className="state-term">“{term}”</span>. Try a different
                    spelling or a shorter term.
                </>
            }
            actions={
                onClear && (
                    <button type="button" className="btn-tone" onClick={onClear}>
                        <i className="ti ti-x" />
                        Clear search
                    </button>
                )
            }
        />
    );
}

interface ErrorStateProps {
    message?: string;
    onRetry?: () => void;
}

export function ErrorState({ message, onRetry }: ErrorStateProps) {
    return (
        <State
            icon="ti ti-alert-triangle"
            tone="danger"
            title="Could not load this data"
            description={message ?? 'The request did not go through. This is usually temporary.'}
            actions={
                onRetry && (
                    <button type="button" className="btn-tone btn-tone--solid" onClick={onRetry}>
                        <i className="ti ti-refresh" />
                        Try again
                    </button>
                )
            }
        />
    );
}

/**
 * Uses the theme's own pill classes (.status-badge / .active-badge /
 * .inactive-badge with a .status-dot) so it matches the Blade tables.
 */
export function StatusBadge({ active, labels }: { active: boolean; labels?: [string, string] }) {
    const [on, off] = labels ?? ['Active', 'Inactive'];

    return (
        <span className={cn('status-badge', active ? 'active-badge' : 'inactive-badge')}>
            <span className="status-dot" />
            {active ? on : off}
        </span>
    );
}
