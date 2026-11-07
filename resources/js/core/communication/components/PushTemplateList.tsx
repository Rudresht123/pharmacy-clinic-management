import { useMemo, useState } from 'react';
import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import { CategoryTag, TemplatePill } from './StatusPill';
import { recipientSummary } from './AutomationRules';
import { TemplateRowEditor } from './TemplateRowEditor';
import type { AutomationRule, MessageTemplate } from '../types';

/**
 * The App notification templates.
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
    rules = [],
    canChoose = false,
    canManage = false,
    canReword = false,
}: {
    templates: MessageTemplate[];
    categories: readonly string[];
    selected: MessageTemplate | null;
    onSelect: (template: MessageTemplate) => void;
    onCreate?: () => void;
    onEdit?: (template: MessageTemplate) => void;
    onRemove?: (template: MessageTemplate) => void;
    /** Every rule on this channel, to say who gets each template. */
    rules?: AutomationRule[];
    /** Open a template's row to change who gets it and what it says. `onEdit` is its name, category and look. */
    canChoose?: boolean;
    canManage?: boolean;
    canReword?: boolean;
}) {
    // Arriving from the overview with one chosen opens it.
    const [opened, setOpened] = useState<number | null>(selected?.id ?? null);
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
            description="What patients and staff read on their lock screen."
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
                        const sending = rules.filter((rule) => rule.message_template_id === template.id);
                        const opens = canManage || canReword || (canChoose && sending.length > 0);
                        const isOpen = opens && opened === template.id;

                        return (
                            <li key={template.id} className={active ? 'is-active' : undefined}>
                                <button
                                    type="button"
                                    className="ph-row-main"
                                    aria-pressed={active}
                                    onClick={() => {
                                        onSelect(template);

                                        if (opens) {
                                            setOpened(isOpen ? null : template.id);
                                        }
                                    }}
                                >
                                    <span className={`comm-glyph is-${template.tone ?? 'violet'}`} aria-hidden="true">
                                        <i className={template.icon ?? 'ti ti-bell'} />
                                    </span>

                                    <span className="ph-row-text">
                                        <span className="ph-row-name">
                                            {template.name}
                                            {!template.is_active && <small>Not in use</small>}
                                            {template.branch_wording && <small>This branch’s wording</small>}
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
                                        <span className="rcp-who-text" title={sending[0] ? (recipientSummary(sending[0]) ?? '') : undefined}>
                                            {sending[0] ? recipientSummary(sending[0]) : 'Campaigns only'}
                                        </span>
                                        <CategoryTag label={template.category} />
                                        <TemplatePill status={template.status} />
                                    </span>
                                </button>

                                {(onEdit || onRemove || opens) && (
                                    <span className="ph-row-actions">
                                        {opens && (
                                            <button
                                                type="button"
                                                className="ph-icon-btn"
                                                title={`Edit ${template.name}`}
                                                aria-label={`Edit ${template.name}`}
                                                aria-expanded={isOpen}
                                                onClick={() => setOpened(isOpen ? null : template.id)}
                                            >
                                                <i className="ti ti-pencil" />
                                            </button>
                                        )}
                                        {onEdit && (
                                            <button
                                                type="button"
                                                className="ph-icon-btn"
                                                title="Name, category and look"
                                                aria-label={`Name, category and look of ${template.name}`}
                                                onClick={() => onEdit(template)}
                                            >
                                                <i className="ti ti-adjustments" />
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

                                {isOpen && (
                                    <div className="rcp-open-block">
                                        <TemplateRowEditor
                                            channel="push"
                                            template={template}
                                            rules={sending}
                                            canChoose={canChoose}
                                            canManage={canManage}
                                            canReword={canReword}
                                            onClose={() => setOpened(null)}
                                        />
                                    </div>
                                )}
                            </li>
                        );
                    })}
                </ul>
            )}
        </Card>
    );
}
