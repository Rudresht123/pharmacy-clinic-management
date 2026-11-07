import { Fragment, useMemo, useState } from 'react';
import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import { Pagination } from '@/shared/components/ui/Pagination';
import { CategoryTag, TemplatePill } from './StatusPill';
import { recipientSummary } from './AutomationRules';
import { TemplateRowEditor } from './TemplateRowEditor';
import type { AutomationRule, MessageTemplate } from '../types';

const PER_PAGE = 8;

/**
 * The template library, as a table.
 *
 * One component for both channels. They differ in two details and neither is
 * worth a second component: WhatsApp narrows by chips over a handful of
 * categories, email searches across twenty templates and a subject line; and
 * the third column shows whichever of the two a patient actually sees first —
 * the subject for email, the opening words for WhatsApp.
 *
 * The row drives the preview beside it, so the table doubles as the selector
 * rather than being a read-only list with a separate chooser.
 */
export function TemplateTable({
    templates,
    categories,
    selected,
    onSelect,
    onCreate,
    onEdit,
    onRemove,
    filter = 'chips',
    actionLabel = 'Use',
    title = 'Message templates',
    description = 'Pre-approved templates for patient communication',
    who,
}: {
    templates: MessageTemplate[];
    categories: readonly string[];
    selected: MessageTemplate | null;
    onSelect: (next: MessageTemplate) => void;
    onCreate?: () => void;
    onEdit?: (template: MessageTemplate) => void;
    onRemove?: (template: MessageTemplate) => void;
    /** Chips suit a handful of categories; search suits a long library. */
    filter?: 'chips' | 'search';
    actionLabel?: string;
    title?: string;
    description?: string;
    /**
     * Says who gets each template, and opens its row to change that and its wording,
     * with `onEdit` left for its name, category and look. Left out on WhatsApp, whose
     * wording is the provider's and which only goes to the patient's own number.
     */
    who?: {
        channel: 'email' | 'push';
        rules: AutomationRule[];
        canChoose: boolean;
        canManage: boolean;
        canReword: boolean;
    };
}) {
    const [opened, setOpened] = useState<number | null>(null);
    const [search, setSearch] = useState('');
    const [category, setCategory] = useState('All');
    const [page, setPage] = useState(1);

    const matched = useMemo(() => {
        const needle = search.trim().toLowerCase();

        return templates.filter((template) => {
            if (category !== 'All' && template.category !== category) {
                return false;
            }

            if (needle === '') {
                return true;
            }

            // Subject and body as well as name: somebody looking for a
            // template remembers what the patient read, not its filing name.
            return (
                template.name.toLowerCase().includes(needle) ||
                (template.subject ?? '').toLowerCase().includes(needle) ||
                template.content.toLowerCase().includes(needle)
            );
        });
    }, [templates, search, category]);

    /*
     * No actions column at all for somebody who holds none of them.
     *
     * An empty column with a heading reads as a feature that is broken; a
     * column that is not there reads as a list, which is what it is.
     */
    const wording = Boolean(who?.canManage || who?.canReword);
    const actionable = Boolean(onEdit || onRemove || wording);

    const sending = (template: MessageTemplate) =>
        (who?.rules ?? []).filter((rule) => rule.message_template_id === template.id);

    const pageCount = Math.max(1, Math.ceil(matched.length / PER_PAGE));
    const current = Math.min(page, pageCount);
    const rows = matched.slice((current - 1) * PER_PAGE, current * PER_PAGE);

    /** Any narrowing invalidates the page somebody was on. */
    function narrow(apply: () => void) {
        apply();
        setPage(1);
    }

    return (
        <Card
            className="comm-card"
            title={title}
            icon="ti ti-template"
            description={description}
            actions={
                <div className="comm-head-actions">
                    {filter === 'search' && (
                        <>
                            <div className="comm-search">
                                <i className="ti ti-search" aria-hidden="true" />

                                <input
                                    type="search"
                                    className="form-control"
                                    placeholder="Search templates…"
                                    value={search}
                                    aria-label="Search templates"
                                    onChange={(event) => narrow(() => setSearch(event.target.value))}
                                />
                            </div>

                            <select
                                className="form-select comm-select"
                                value={category}
                                aria-label="Filter by category"
                                onChange={(event) => narrow(() => setCategory(event.target.value))}
                            >
                                {categories.map((item) => (
                                    <option key={item} value={item}>
                                        {item === 'All' ? 'All categories' : item}
                                    </option>
                                ))}
                            </select>
                        </>
                    )}

                    {onCreate && (
                        <Button size="sm" icon="ti ti-plus" onClick={onCreate}>
                            Create template
                        </Button>
                    )}
                </div>
            }
        >
            {filter === 'chips' && (
                <div className="comm-filters" role="group" aria-label="Template categories">
                    {categories.map((item) => (
                        <button
                            key={item}
                            type="button"
                            className={`comm-filter${category === item ? ' is-active' : ''}`}
                            aria-pressed={category === item}
                            onClick={() => narrow(() => setCategory(item))}
                        >
                            {item === 'All' ? 'All templates' : item}
                        </button>
                    ))}
                </div>
            )}

            {rows.length === 0 ? (
                <p className="pd-quiet">
                    <i className="ti ti-mood-empty" aria-hidden="true" />
                    {templates.length === 0
                        ? 'No templates yet. Create one to start sending.'
                        : 'No templates match that.'}
                </p>
            ) : (
                <div className="pf-table-wrap">
                    <table className="pf-table comm-table">
                        <thead>
                            <tr>
                                <th>Template name</th>
                                <th>Category</th>
                                <th>{filter === 'search' ? 'Subject' : 'Message preview'}</th>
                                {who && <th>Who gets it</th>}
                                <th>Status</th>
                                {actionable && <th className="comm-actions-col">Actions</th>}
                            </tr>
                        </thead>

                        <tbody>
                            {rows.map((template) => {
                                const rules = sending(template);
                                const opens = wording || Boolean(who?.canChoose && rules.length > 0);
                                const isOpen = opens && opened === template.id;

                                return (
                                <Fragment key={template.id}>
                                <tr
                                    className={selected?.id === template.id ? 'is-active' : undefined}
                                    onClick={() => {
                                        onSelect(template);

                                        if (opens) {
                                            setOpened(isOpen ? null : template.id);
                                        }
                                    }}
                                >
                                    <td className="pf-strong">
                                        <span className="comm-row-name">
                                            <span
                                                className={`comm-glyph is-${template.tone ?? 'sky'}`}
                                                aria-hidden="true"
                                            >
                                                <i className={template.icon ?? 'ti ti-template'} />
                                            </span>

                                            {template.name}

                                            {template.branch_wording && (
                                                <small className="comm-own-wording">This branch’s wording</small>
                                            )}
                                        </span>
                                    </td>

                                    <td>
                                        <CategoryTag label={template.category} />
                                    </td>

                                    {/*
                                        The clamp lives on a span, never on the
                                        cell: a <td> told to be a -webkit-box
                                        stops behaving as a table cell, and the
                                        third line leaks out under the ellipsis.
                                    */}
                                    <td className="comm-preview-cell">
                                        <span className="comm-preview">
                                            {filter === 'search' ? template.subject : template.content}
                                        </span>
                                    </td>

                                    {who && (
                                        <td className="rcp-who-cell">
                                            {opens ? (
                                                <button type="button" className="rcp-toggle" aria-expanded={isOpen}>
                                                    <span>{rules.length > 0 ? recipientSummary(rules[0]) : 'Campaigns only'}</span>
                                                    <i
                                                        className={isOpen ? 'ti ti-chevron-up' : 'ti ti-chevron-down'}
                                                        aria-hidden="true"
                                                    />
                                                </button>
                                            ) : (
                                                <span className="rcp-who-text">
                                                    {rules.length > 0 ? recipientSummary(rules[0]) : 'Campaigns only'}
                                                </span>
                                            )}
                                        </td>
                                    )}

                                    <td>
                                        <TemplatePill status={template.status} />
                                    </td>

                                    {/*
                                        The row already selects; these must not
                                        select again on their way to doing
                                        something else, so each stops the click.
                                    */}
                                    {actionable && (
                                    <td className="comm-actions-col">
                                        <div className="comm-row-actions">
                                            {who && opens ? (
                                                <>
                                                    <Button
                                                        variant="light"
                                                        size="sm"
                                                        aria-expanded={isOpen}
                                                        onClick={(event) => {
                                                            event.stopPropagation();
                                                            setOpened(isOpen ? null : template.id);
                                                        }}
                                                    >
                                                        {actionLabel}
                                                    </Button>

                                                    {onEdit && (
                                                        <button
                                                            type="button"
                                                            className="comm-more"
                                                            title="Name, category and look"
                                                            aria-label={`Name, category and look of ${template.name}`}
                                                            onClick={(event) => {
                                                                event.stopPropagation();
                                                                onEdit(template);
                                                            }}
                                                        >
                                                            <i className="ti ti-adjustments" />
                                                        </button>
                                                    )}
                                                </>
                                            ) : (
                                                onEdit && (
                                                    <Button
                                                        variant="light"
                                                        size="sm"
                                                        onClick={(event) => {
                                                            event.stopPropagation();
                                                            onEdit(template);
                                                        }}
                                                    >
                                                        {actionLabel}
                                                    </Button>
                                                )
                                            )}

                                            {onRemove && (
                                                <button
                                                    type="button"
                                                    className="comm-more"
                                                    title={`Remove ${template.name}`}
                                                    aria-label={`Remove ${template.name}`}
                                                    onClick={(event) => {
                                                        event.stopPropagation();
                                                        onRemove(template);
                                                    }}
                                                >
                                                    <i className="ti ti-trash" />
                                                </button>
                                            )}
                                        </div>
                                    </td>
                                    )}
                                </tr>

                                {isOpen && who && (
                                    <tr className="rcp-open-row">
                                        <td colSpan={4 + 1 + (actionable ? 1 : 0)}>
                                            <TemplateRowEditor
                                                channel={who.channel}
                                                template={template}
                                                rules={rules}
                                                canChoose={who.canChoose}
                                                canManage={who.canManage}
                                                canReword={who.canReword}
                                                onClose={() => setOpened(null)}
                                            />
                                        </td>
                                    </tr>
                                )}
                                </Fragment>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            )}

            {matched.length > PER_PAGE && (
                <Pagination
                    page={current}
                    pageCount={pageCount}
                    total={matched.length}
                    perPage={PER_PAGE}
                    onChange={setPage}
                />
            )}
        </Card>
    );
}
