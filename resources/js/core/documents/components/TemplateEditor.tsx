import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Card } from '@/shared/components/ui/Card';
import type { Placeholder, TemplateConfig } from '../templates';

/** Which group of sections is on screen. */
export type EditorTab = 'content' | 'paper' | 'permissions' | 'advanced';

export const EDITOR_TABS: { value: EditorTab; label: string; icon: string }[] = [
    { value: 'content', label: 'Content & layout', icon: 'ti ti-layout-list' },
    { value: 'paper', label: 'Paper & print', icon: 'ti ti-printer' },
    { value: 'permissions', label: 'Permissions', icon: 'ti ti-lock' },
    { value: 'advanced', label: 'Advanced', icon: 'ti ti-adjustments' },
];

/**
 * The letterhead, as a form rather than as markup.
 *
 * STRUCTURED ON PURPOSE. The obvious alternative is a raw HTML box, and it
 * would be worse in both directions: the renderer is dompdf, which has no
 * flexbox and no grid, so markup that looks right in a browser collapses in
 * the PDF — and the person editing this is a branch manager, not somebody who
 * should have to discover that. Every control here maps to one path in
 * TemplateConfig, which is also the vocabulary of locks.
 *
 * A LOCKED FIELD IS DISABLED, NOT HIDDEN. Somebody has to be able to see what
 * the organisation decided — a setting that vanishes reads as a bug, and the
 * first thing they do is ask why their branch has fewer options than another.
 */
export function TemplateEditor({
    tab,
    config,
    placeholders,
    lockedPaths,
    disabled = false,
    logoHint,
    onChange,
}: {
    tab: EditorTab;
    config: TemplateConfig;
    placeholders: Placeholder[];
    lockedPaths: string[];
    disabled?: boolean;
    /** Where the printed logo actually comes from — see the Letterhead note. */
    logoHint?: { label: string; to: string };
    onChange: (next: TemplateConfig) => void;
}) {
    const [open, setOpen] = useState<string>('header');

    /**
     * Whether a path is locked — directly, or by a lock on its section.
     * `header` locks `header.title` too, which is what "the whole header"
     * means on the locks panel.
     */
    const isLocked = (path: string) =>
        lockedPaths.some((locked) => path === locked || path.startsWith(`${locked}.`));

    const off = (path: string) => disabled || isLocked(path);

    function set<S extends keyof TemplateConfig>(
        section: S,
        key: keyof TemplateConfig[S],
        value: unknown,
    ) {
        onChange({
            ...config,
            [section]: { ...config[section], [key]: value },
        });
    }

    if (tab === 'paper') {
        return (
            <Section
                id="layout"
                title="Paper & print"
                hint="Paper size, margins and how large the type sets."
                icon="ti ti-printer"
                open={open}
                onToggle={setOpen}
                alwaysOpen
            >
                <div className="row g-2 mb-3">
                    <div className="col-4">
                        <Label text="Paper" locked={isLocked('layout.paper')} />
                        <select
                            className="form-select form-select-sm"
                            value={config.layout.paper}
                            disabled={off('layout.paper')}
                            onChange={(e) => set('layout', 'paper', e.target.value)}
                        >
                            <option value="a4">A4</option>
                            <option value="a5">A5</option>
                            <option value="receipt">Till roll (80mm)</option>
                        </select>
                    </div>
                    <div className="col-4">
                        <Label text="Type size (pt)" />
                        <input
                            type="number"
                            min={7}
                            max={16}
                            className="form-control form-control-sm"
                            value={config.layout.font_size}
                            disabled={off('layout.font_size')}
                            onChange={(e) =>
                                set('layout', 'font_size', parseInt(e.target.value, 10) || 11)
                            }
                        />
                    </div>
                    <div className="col-4">
                        <Label text="Accent" locked={isLocked('layout.accent')} />
                        <input
                            type="color"
                            className="form-control form-control-color form-control-sm w-100"
                            value={config.layout.accent}
                            disabled={off('layout.accent')}
                            onChange={(e) => set('layout', 'accent', e.target.value)}
                        />
                    </div>
                </div>

                <Label text="Margins (mm)" />
                <div className="row g-2">
                    {(['top', 'right', 'bottom', 'left'] as const).map((side) => (
                        <div className="col-3" key={side}>
                            <input
                                type="number"
                                min={0}
                                max={40}
                                className="form-control form-control-sm"
                                aria-label={`${side} margin`}
                                value={config.layout.margin_mm[side]}
                                disabled={off('layout.margin_mm')}
                                onChange={(e) =>
                                    set('layout', 'margin_mm', {
                                        ...config.layout.margin_mm,
                                        [side]: parseInt(e.target.value, 10) || 0,
                                    })
                                }
                            />
                            <small className="text-muted text-capitalize">{side}</small>
                        </div>
                    ))}
                </div>
            </Section>
        );
    }

    if (tab === 'advanced') {
        return <PlaceholderList placeholders={placeholders} />;
    }

    if (tab === 'permissions') {
        // The locks panel itself is the organisation's, and lives beside the
        // document list — this tab explains why it is not here.
        return (
            <Card>
                <h6 className="mb-1">
                    <i className="ti ti-lock me-2" />
                    Locked fields
                </h6>
                <p className="text-muted fs-13 mb-0">
                    {lockedPaths.length === 0
                        ? 'Nothing on this template is locked. A branch may change every setting on it.'
                        : 'Your organisation has locked the settings below. They show with a lock beside them and cannot be changed here.'}
                </p>

                {lockedPaths.length > 0 && (
                    <ul className="mt-2 mb-0 fs-13">
                        {lockedPaths.map((path) => (
                            <li key={path}>
                                <code>{path}</code>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>
        );
    }

    /* ------------------------------------------------ content & layout */

    return (
        <>
            <Section
                id="header"
                title="Letterhead"
                hint="Your clinic's logo, name and header information."
                icon="ti ti-layout-navbar"
                open={open}
                onToggle={setOpen}
            >
                <Toggle
                    label="Show the logo"
                    checked={config.header.show_logo}
                    disabled={off('header.show_logo')}
                    onChange={(v) => set('header', 'show_logo', v)}
                />

                {config.header.show_logo && (
                    <>
                        <div className="row g-2 mb-2">
                            <div className="col-6">
                                <Label text="Which logo" locked={isLocked('header.logo_source')} />
                                <select
                                    className="form-select form-select-sm"
                                    value={config.header.logo_source}
                                    disabled={off('header.logo_source')}
                                    onChange={(e) => set('header', 'logo_source', e.target.value)}
                                >
                                    <option value="branch">This branch&rsquo;s</option>
                                    <option value="organization">The organisation&rsquo;s</option>
                                    <option value="none">None</option>
                                </select>
                            </div>
                            <div className="col-6">
                                <Label text="Position" locked={isLocked('header.logo_position')} />
                                <select
                                    className="form-select form-select-sm"
                                    value={config.header.logo_position}
                                    disabled={off('header.logo_position')}
                                    onChange={(e) => set('header', 'logo_position', e.target.value)}
                                >
                                    <option value="left">Left</option>
                                    <option value="center">Centre</option>
                                    <option value="right">Right</option>
                                </select>
                            </div>
                        </div>

                        {/*
                            THE LOGO IS NOT UPLOADED HERE, and the panel says
                            so rather than offering a button that would have
                            to explain itself afterwards. What prints is the
                            branch's own mark, or the organisation's — both
                            live on those records, and a second copy kept
                            per-template is a second thing to keep in step.
                        */}
                        {logoHint && (
                            <p className="dr-sub mb-3">
                                <i className="ti ti-info-circle me-1" />
                                The logo itself is the {config.header.logo_source === 'organization'
                                    ? 'organisation'
                                    : 'branch'}
                                &rsquo;s. <Link to={logoHint.to}>{logoHint.label}</Link> to change
                                it — the preview shows what will actually print.
                            </p>
                        )}
                    </>
                )}

                <Field
                    label="Document title"
                    value={config.header.title}
                    locked={isLocked('header.title')}
                    disabled={off('header.title')}
                    onChange={(v) => set('header', 'title', v)}
                    hint="Printed at the top — “Invoice”, “Tax invoice”, “Receipt”."
                />

                <div className="row g-2">
                    <div className="col-md-6">
                        <Field
                            label="Registered clinic name"
                            value={config.header.legal_name}
                            locked={isLocked('header.legal_name')}
                            disabled={off('header.legal_name')}
                            onChange={(v) => set('header', 'legal_name', v)}
                        />
                    </div>
                    <div className="col-md-6">
                        <Field
                            label="Registration / licence number"
                            value={config.header.registration_no}
                            locked={isLocked('header.registration_no')}
                            disabled={off('header.registration_no')}
                            onChange={(v) => set('header', 'registration_no', v)}
                        />
                    </div>
                </div>

                <Lines
                    label="Address lines"
                    hint="One line per row. Placeholders are filled in when printed."
                    addLabel="Add address line"
                    value={config.header.lines}
                    disabled={off('header.lines')}
                    onChange={(v) => set('header', 'lines', v)}
                />

                <Toggle
                    label="Rule under the letterhead"
                    checked={config.header.show_divider}
                    disabled={off('header.show_divider')}
                    onChange={(v) => set('header', 'show_divider', v)}
                />
            </Section>

            <Section
                id="patient"
                title="Patient & visit"
                hint="Which patient and visit details to show."
                icon="ti ti-user"
                open={open}
                onToggle={setOpen}
            >
                <Toggle
                    label="Patient details — name, ID, age, mobile"
                    checked={config.body.show_patient}
                    disabled={off('body.show_patient')}
                    onChange={(v) => set('body', 'show_patient', v)}
                />
                <Toggle
                    label="Visit details — doctor, registration number, date"
                    checked={config.body.show_visit}
                    disabled={off('body.show_visit')}
                    onChange={(v) => set('body', 'show_visit', v)}
                />
                <Toggle
                    label="Clinical details — complaint, diagnosis, advice"
                    checked={config.body.show_clinical}
                    disabled={off('body.show_clinical')}
                    onChange={(v) => set('body', 'show_clinical', v)}
                />
            </Section>

            <Section
                id="body"
                title="Items & totals"
                hint="The charge table, and the text around it."
                icon="ti ti-table"
                open={open}
                onToggle={setOpen}
            >
                {config.body.tables.length > 0 && (
                    <div className="mb-3">
                        <Label text="Table heading" />
                        {config.body.tables.map((table, index) => (
                            <input
                                key={table.token}
                                type="text"
                                className="form-control form-control-sm mb-1"
                                value={table.title}
                                disabled={off('body.tables')}
                                onChange={(e) =>
                                    set(
                                        'body',
                                        'tables',
                                        config.body.tables.map((t, i) =>
                                            i === index ? { ...t, title: e.target.value } : t,
                                        ),
                                    )
                                }
                            />
                        ))}
                        <small className="text-muted">
                            The repeating block — charges, medicines, tests.
                        </small>
                    </div>
                )}

                <Area
                    label="Intro paragraph"
                    value={config.body.intro}
                    disabled={off('body.intro')}
                    onChange={(v) => set('body', 'intro', v)}
                    hint="Printed above the details. Leave blank for none."
                />

                <Area
                    label="Notes"
                    value={config.body.notes}
                    disabled={off('body.notes')}
                    onChange={(v) => set('body', 'notes', v)}
                    hint="Printed below the details."
                />
            </Section>

            <Section
                id="footer"
                title="Footer & signature"
                hint="Notes, terms and the signature line."
                icon="ti ti-layout-bottombar"
                open={open}
                onToggle={setOpen}
            >
                <Area
                    label="Terms / disclaimer"
                    value={config.footer.terms}
                    locked={isLocked('footer.terms')}
                    disabled={off('footer.terms')}
                    onChange={(v) => set('footer', 'terms', v)}
                    hint="Printed in a box of its own, above the footer lines."
                />

                <Lines
                    label="Footer lines"
                    addLabel="Add footer line"
                    value={config.footer.lines}
                    disabled={off('footer.lines')}
                    onChange={(v) => set('footer', 'lines', v)}
                />

                <Toggle
                    label="Signature line"
                    checked={config.footer.show_signature}
                    disabled={off('footer.show_signature')}
                    onChange={(v) => set('footer', 'show_signature', v)}
                />

                {config.footer.show_signature && (
                    <Field
                        label="Signature label"
                        value={config.footer.signature_label}
                        disabled={off('footer.signature_label')}
                        onChange={(v) => set('footer', 'signature_label', v)}
                    />
                )}

                <Toggle
                    label="Page numbers"
                    checked={config.footer.show_page_numbers}
                    disabled={off('footer.show_page_numbers')}
                    onChange={(v) => set('footer', 'show_page_numbers', v)}
                />
            </Section>
        </>
    );
}

/* ---------------------------------------------------------------- pieces */

function Section({
    id,
    title,
    hint,
    icon,
    open,
    onToggle,
    alwaysOpen = false,
    children,
}: {
    id: string;
    title: string;
    hint: string;
    icon: string;
    open: string;
    onToggle: (id: string) => void;
    alwaysOpen?: boolean;
    children: React.ReactNode;
}) {
    const isOpen = alwaysOpen || open === id;

    return (
        <div className="dt-accordion-card">
            <button
                type="button"
                className="dt-accordion-trigger"
                onClick={() => !alwaysOpen && onToggle(isOpen ? '' : id)}
            >
                <div className="dt-accordion-header-left">
                    <i className={`dt-accordion-icon ${icon}`} aria-hidden="true" />
                    <div>
                        <span className="dt-accordion-title">{title}</span>
                        <span className="dt-accordion-hint">{hint}</span>
                    </div>
                </div>
                {!alwaysOpen && (
                    <i className={`dt-accordion-chevron ti ti-chevron-${isOpen ? 'up' : 'down'}`} aria-hidden="true" />
                )}
            </button>

            {isOpen && <div className="dt-accordion-body">{children}</div>}
        </div>
    );
}

function Label({ text, locked }: { text: string; locked?: boolean }) {
    return (
        <label className="dt-form-label">
            {text}
            {locked && (
                <i
                    className="ti ti-lock ms-1 text-warning"
                    title="Locked by your organisation"
                    aria-label="Locked by your organisation"
                />
            )}
        </label>
    );
}

function Field({
    label,
    value,
    onChange,
    disabled,
    locked,
    hint,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    disabled?: boolean;
    locked?: boolean;
    hint?: string;
}) {
    return (
        <div className="dt-form-group">
            <Label text={label} locked={locked} />
            <input
                type="text"
                className="dt-input"
                value={value}
                disabled={disabled}
                onChange={(e) => onChange(e.target.value)}
            />
            {hint && <small className="dt-form-hint">{hint}</small>}
        </div>
    );
}

function Area({
    label,
    value,
    onChange,
    disabled,
    locked,
    hint,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    disabled?: boolean;
    locked?: boolean;
    hint?: string;
}) {
    return (
        <div className="dt-form-group">
            <Label text={label} locked={locked} />
            <textarea
                className="dt-input"
                style={{ height: 'auto', minHeight: '70px', padding: '8px 10px' }}
                rows={3}
                value={value}
                disabled={disabled}
                onChange={(e) => onChange(e.target.value)}
            />
            {hint && <small className="dt-form-hint">{hint}</small>}
        </div>
    );
}

function Toggle({
    label,
    checked,
    onChange,
    disabled,
}: {
    label: string;
    checked: boolean;
    onChange: (value: boolean) => void;
    disabled?: boolean;
}) {
    return (
        <label className="form-check form-switch mb-1">
            <input
                type="checkbox"
                className="form-check-input"
                checked={checked}
                disabled={disabled}
                onChange={(e) => onChange(e.target.checked)}
            />
            <span className="form-check-label fs-13 fw-600">{label}</span>
        </label>
    );
}

/** A list of lines edited as a list, because that is what it prints as. */
function Lines({
    label,
    value,
    onChange,
    disabled,
    hint,
    addLabel = 'Add line',
}: {
    label: string;
    value: string[];
    onChange: (value: string[]) => void;
    disabled?: boolean;
    hint?: string;
    addLabel?: string;
}) {
    return (
        <div className="dt-form-group">
            <Label text={label} />

            {value.map((line, index) => (
                <div className="dt-address-line" key={index}>
                    <input
                        type="text"
                        className="dt-input flex-fill"
                        value={line}
                        disabled={disabled}
                        onChange={(e) =>
                            onChange(value.map((l, i) => (i === index ? e.target.value : l)))
                        }
                    />
                    <button
                        type="button"
                        className="btn btn-sm btn-outline-danger"
                        disabled={disabled}
                        aria-label="Remove line"
                        onClick={() => onChange(value.filter((_, i) => i !== index))}
                    >
                        <i className="ti ti-trash" />
                    </button>
                </div>
            ))}

            <button
                type="button"
                className="dt-add-line-btn"
                disabled={disabled}
                onClick={() => onChange([...value, ''])}
            >
                <i className="ti ti-plus" />
                {addLabel}
            </button>

            {hint && <small className="dt-form-hint">{hint}</small>}
        </div>
    );
}

/**
 * What somebody can type into a text field, and what it becomes.
 *
 * Click-to-copy rather than drag-and-drop: the fields above are plain inputs,
 * and a token pasted into one is the whole interaction.
 */
function PlaceholderList({ placeholders }: { placeholders: Placeholder[] }) {
    const [copied, setCopied] = useState<string | null>(null);

    const groups = placeholders.reduce<Record<string, Placeholder[]>>((all, placeholder) => {
        (all[placeholder.group] ??= []).push(placeholder);

        return all;
    }, {});

    return (
        <Card>
            <h6 className="mb-1">
                <i className="ti ti-braces me-2" />
                Placeholders
            </h6>
            <p className="dr-sub mb-3">
                Click one to copy, then paste it into any text field. It is replaced with the real
                value when the document is printed.
            </p>

            {Object.entries(groups).map(([group, entries]) => (
                <div className="mb-3" key={group}>
                    <div className="dr-sub text-capitalize mb-1">{group}</div>
                    <div className="d-flex flex-wrap gap-1">
                        {entries.map((placeholder) => {
                            const token = `{{${placeholder.token}}}`;

                            return (
                                <button
                                    key={placeholder.token}
                                    type="button"
                                    className={`btn btn-sm ${copied === token ? 'btn-success' : 'btn-outline-secondary'}`}
                                    title={`${placeholder.label} — e.g. ${placeholder.sample}`}
                                    onClick={() => {
                                        void navigator.clipboard.writeText(token);
                                        setCopied(token);
                                        window.setTimeout(() => setCopied(null), 1200);
                                    }}
                                >
                                    {copied === token ? 'Copied' : placeholder.label}
                                </button>
                            );
                        })}
                    </div>
                </div>
            ))}
        </Card>
    );
}
