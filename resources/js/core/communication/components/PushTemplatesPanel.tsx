import { useMemo, useState } from 'react';
import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import { RowActions } from '@/shared/components/ui/RowActions';
import type { AutomationRule, MessageTemplate } from '../types';

type Source = 'system' | 'mine' | 'branch';

const SOURCES: { value: Source; label: string }[] = [
    { value: 'system', label: 'System templates' },
    { value: 'mine', label: 'My templates' },
    { value: 'branch', label: 'Branch templates' },
];

/**
 * The template library as the overview shows it — Global → Organisation →
 * Branch, as three tabs: what the platform supplied, what the clinic wrote,
 * and what a branch has reworded for its own patients.
 *
 * Each row says when it goes out, read from the automation rule that sends
 * it, because "Appointment confirmation" alone does not tell anybody whether
 * patients are actually getting it.
 */
export function PushTemplatesPanel({
    templates,
    rules,
    onOpen,
    onCreate,
    onEdit,
    onRemove,
}: {
    templates: MessageTemplate[];
    rules: AutomationRule[];
    /** Opens the template in the Templates view, with its preview. */
    onOpen: (template: MessageTemplate) => void;
    onCreate?: () => void;
    onEdit?: (template: MessageTemplate) => void;
    onRemove?: (template: MessageTemplate) => void;
}) {
    const [source, setSource] = useState<Source>('system');
    const [search, setSearch] = useState('');

    const counts: Record<Source, number> = {
        system: templates.filter((template) => template.is_default).length,
        mine: templates.filter((template) => !template.is_default).length,
        branch: templates.filter((template) => (template.branch_wordings ?? 0) > 0).length,
    };

    const shown = useMemo(() => {
        const needle = search.trim().toLowerCase();

        return templates.filter((template) => {
            const inSource =
                source === 'system'
                    ? template.is_default
                    : source === 'mine'
                      ? !template.is_default
                      : (template.branch_wordings ?? 0) > 0;

            return (
                inSource &&
                (needle === '' ||
                    template.name.toLowerCase().includes(needle) ||
                    template.content.toLowerCase().includes(needle))
            );
        });
    }, [templates, source, search]);

    /** When it goes out, in the words of the rule that sends it. */
    function when(template: MessageTemplate): string {
        const rule = rules.find((item) => item.message_template_id === template.id);

        if (rule) {
            return rule.description ?? `Sent by “${rule.title}”`;
        }

        if (source === 'branch') {
            const count = template.branch_wordings ?? 0;

            return `${count} ${count === 1 ? 'branch sends' : 'branches send'} its own wording`;
        }

        return `${template.category} · sent from a campaign`;
    }

    return (
        <Card
            className="ph-card"
            title="Notification templates"
            icon="ti ti-file-text"
            description="Manage push notification templates for common events"
            actions={
                onCreate && (
                    <Button size="sm" variant="outline" className="ph-outline" icon="ti ti-plus" onClick={onCreate}>
                        Create template
                    </Button>
                )
            }
        >
            <div className="ph-subtabs" role="tablist" aria-label="Template source">
                {SOURCES.map((item) => (
                    <button
                        key={item.value}
                        type="button"
                        role="tab"
                        aria-selected={source === item.value}
                        className={source === item.value ? 'is-active' : undefined}
                        onClick={() => setSource(item.value)}
                    >
                        {item.label}
                        <span>{counts[item.value]}</span>
                    </button>
                ))}
            </div>

            <label className="ph-search is-block">
                <i className="ti ti-search" aria-hidden="true" />
                <input
                    type="search"
                    placeholder="Search templates…"
                    value={search}
                    aria-label="Search templates"
                    onChange={(event) => setSearch(event.target.value)}
                />
            </label>

            {shown.length === 0 ? (
                <p className="ph-empty">
                    <i className="ti ti-file-off" aria-hidden="true" />
                    {source === 'mine'
                        ? 'No templates of your own yet.'
                        : source === 'branch'
                          ? 'No branch has reworded a template yet.'
                          : 'No template matches that search.'}
                </p>
            ) : (
                <ul className="ph-tpl">
                    {shown.map((template) => (
                        <li key={template.id}>
                            <button type="button" className="ph-tpl-main" onClick={() => onOpen(template)}>
                                <span className={`ph-tpl-icon is-${template.tone ?? 'violet'}`} aria-hidden="true">
                                    <i className={template.icon ?? 'ti ti-bell'} />
                                </span>

                                <span className="ph-tpl-text">
                                    <b>{template.name}</b>
                                    <small>{when(template)}</small>
                                </span>
                            </button>

                            <span className={`ph-badge ${template.is_active ? 'is-on' : 'is-off'}`}>
                                {template.is_active ? 'Active' : 'Off'}
                            </span>

                            {(onEdit || onRemove) && (
                                <RowActions
                                    onEdit={onEdit ? () => onEdit(template) : undefined}
                                    onDelete={onRemove ? () => onRemove(template) : undefined}
                                    editTitle="Edit template"
                                    deleteTitle="Remove template"
                                />
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}
