import { useMemo, useState } from 'react';
import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import { CategoryTag, TemplatePill } from './StatusPill';
import type { MessageTemplate } from '../types';

/**
 * The App notification templates, as the list that drives the preview.
 *
 * A list rather than the email/WhatsApp table: a notification is a title and
 * a line, and that is what a row shows — the same two things the patient
 * reads on the lock screen — instead of a "Subject" column that repeats the
 * template's name.
 *
 * The filters get a row of their own under the heading, so the heading is
 * never squeezed into a column of one word per line.
 */
export function PushTemplateList({
    templates,
    categories,
    selected,
    onSelect,
    onCreate,
    onEdit,
    onRemove,
}: {
    templates: MessageTemplate[];
    categories: readonly string[];
    selected: MessageTemplate | null;
    onSelect: (template: MessageTemplate) => void;
    onCreate?: () => void;
    onEdit?: (template: MessageTemplate) => void;
    onRemove?: (template: MessageTemplate) => void;
}) {
    const [search, setSearch] = useState('');
    const [category, setCategory] = useState('All');

    const matched = useMemo(() => {
        const needle = search.trim().toLowerCase();

        return templates.filter(
            (template) =>
                (category === 'All' || template.category === category) &&
                (needle === '' ||
                    template.name.toLowerCase().includes(needle) ||
                    (template.subject ?? '').toLowerCase().includes(needle) ||
                    template.content.toLowerCase().includes(needle)),
        );
    }, [templates, search, category]);

    // Only the categories in use, so a chip never leads to an empty list.
    const used = categories.filter(
        (item) => item === 'All' || templates.some((template) => template.category === item),
    );

    return (
        <Card
            className="ph-card"
            title="Notification templates"
            icon="ti ti-template"
            description="What patients read on their lock screen. Choose one to preview it."
            actions={
                onCreate && (
                    <Button size="sm" icon="ti ti-plus" onClick={onCreate}>
                        Create template
                    </Button>
                )
            }
        >
            <div className="ph-toolbar">
                <label className="ph-search">
                    <i className="ti ti-search" aria-hidden="true" />
                    <input
                        type="search"
                        placeholder="Search by name or wording…"
                        value={search}
                        aria-label="Search templates"
                        onChange={(event) => setSearch(event.target.value)}
                    />
                </label>

                <div className="ph-chips" role="group" aria-label="Filter by category">
                    {used.map((item) => (
                        <button
                            key={item}
                            type="button"
                            className={category === item ? 'is-active' : undefined}
                            aria-pressed={category === item}
                            onClick={() => setCategory(item)}
                        >
                            {item}
                        </button>
                    ))}
                </div>
            </div>

            {matched.length === 0 ? (
                <p className="ph-empty">
                    <i className="ti ti-bell-off" aria-hidden="true" />
                    {templates.length === 0
                        ? 'No templates yet. Create one to start notifying patients.'
                        : 'No template matches that search.'}
                </p>
            ) : (
                <ul className="ph-list">
                    {matched.map((template) => {
                        const active = selected?.id === template.id;

                        return (
                            <li key={template.id} className={active ? 'is-active' : undefined}>
                                <button
                                    type="button"
                                    className="ph-row-main"
                                    aria-pressed={active}
                                    onClick={() => onSelect(template)}
                                >
                                    <span className={`comm-glyph is-${template.tone ?? 'violet'}`} aria-hidden="true">
                                        <i className={template.icon ?? 'ti ti-bell'} />
                                    </span>

                                    <span className="ph-row-text">
                                        <span className="ph-row-name">
                                            {template.name}
                                            {!template.is_active && <small>Not in use</small>}
                                        </span>
                                        {/* The title only where it says something the
                                            name does not — otherwise the row reads
                                            the same words twice. */}
                                        <span className="ph-row-line">
                                            {template.subject && template.subject !== template.name && (
                                                <>
                                                    <b>{template.subject}</b>
                                                    {' — '}
                                                </>
                                            )}
                                            {template.content}
                                        </span>
                                    </span>

                                    <span className="ph-row-meta">
                                        <CategoryTag label={template.category} />
                                        <TemplatePill status={template.status} />
                                    </span>
                                </button>

                                {(onEdit || onRemove) && (
                                    <span className="ph-row-actions">
                                        {onEdit && (
                                            <button
                                                type="button"
                                                className="ph-icon-btn"
                                                title={`Edit ${template.name}`}
                                                aria-label={`Edit ${template.name}`}
                                                onClick={() => onEdit(template)}
                                            >
                                                <i className="ti ti-pencil" />
                                            </button>
                                        )}
                                        {onRemove && (
                                            <button
                                                type="button"
                                                className="ph-icon-btn is-danger"
                                                title={`Remove ${template.name}`}
                                                aria-label={`Remove ${template.name}`}
                                                onClick={() => onRemove(template)}
                                            >
                                                <i className="ti ti-trash" />
                                            </button>
                                        )}
                                    </span>
                                )}
                            </li>
                        );
                    })}
                </ul>
            )}
        </Card>
    );
}
