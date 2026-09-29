import { useEffect, useRef, useState } from 'react';
import type { Placeholder, TemplateConfig } from '../templates';
import { TemplateLocks } from './TemplateLocks';

/**
 * The letterhead mark, and what may be done to it from here.
 *
 * `own` separates "this branch has a logo" from "this branch prints one" —
 * a branch with none still prints the organisation's, and offering to remove
 * a mark it does not have would be a button that does nothing.
 */
export interface LogoControl {
    /** What will actually print, branch's own or inherited. */
    url: string | null;
    own: boolean;
    canChange: boolean;
    busy: boolean;
    onPick: (file: File) => void;
    onRemove: () => void;
    /** Why it cannot be changed here, when it cannot. */
    note: string | null;
}

/** Which group of sections the tab strip is pointing at. */
export type EditorTab = 'content' | 'paper' | 'permissions' | 'advanced';

export const EDITOR_TABS: { value: EditorTab; label: string; icon: string }[] = [
    { value: 'content', label: 'Content & Layout', icon: 'ti ti-layout-list' },
    { value: 'paper', label: 'Paper & Print', icon: 'ti ti-printer' },
    { value: 'permissions', label: 'Permissions', icon: 'ti ti-lock' },
    { value: 'advanced', label: 'Advanced', icon: 'ti ti-adjustments' },
];

/**
 * Every panel of the template, in one column.
 *
 * ONE LIST, NOT FOUR SCREENS. The tabs above do not swap the page out; they
 * open the section they name. Somebody setting up a bill wants to see that a
 * footer and a paper size exist without hunting for the tab that hides them,
 * and a section that vanishes when you click elsewhere reads as a bug.
 */
const SECTIONS: {
    id: string;
    group: EditorTab;
    title: string;
    hint: string;
    icon: string;
}[] = [
    {
        id: 'header',
        group: 'content',
        title: 'Letterhead',
        hint: "Manage your clinic logo, name and header information.",
        icon: 'ti ti-layout-navbar',
    },
    {
        id: 'patient',
        group: 'content',
        title: 'Patient & Visit',
        hint: 'Choose which patient and visit details to show.',
        icon: 'ti ti-user',
    },
    {
        id: 'body',
        group: 'content',
        title: 'Invoice Details',
        hint: 'Configure item table, totals, tax and other invoice information.',
        icon: 'ti ti-file-invoice',
    },
    {
        id: 'footer',
        group: 'content',
        title: 'Footer',
        hint: 'Add notes, terms and signature.',
        icon: 'ti ti-layout-bottombar',
    },
    {
        id: 'layout',
        group: 'paper',
        title: 'Paper & Print',
        hint: 'Set paper size, orientation, margins and print options.',
        icon: 'ti ti-printer',
    },
    {
        id: 'permissions',
        group: 'permissions',
        title: 'Permissions / Locks',
        hint: 'Lock specific fields for branches.',
        icon: 'ti ti-lock',
    },
    {
        id: 'placeholders',
        group: 'advanced',
        title: 'Placeholders',
        hint: 'Insert dynamic values into your template.',
        icon: 'ti ti-braces',
    },
];

/**
 * The template, as a form rather than as markup.
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
    logo,
    organizationName,
    locks,
    onChange,
}: {
    tab: EditorTab;
    config: TemplateConfig;
    placeholders: Placeholder[];
    lockedPaths: string[];
    disabled?: boolean;
    /** The printed mark, and whether it can be changed from here. */
    logo?: LogoControl;
    /** Stands in for {{organization_name}} on the logo card. */
    organizationName?: string | null;
    /** Present only on the organisation's own default, for somebody who may lock. */
    locks?: { templateId: number; current: string[] } | null;
    onChange: (next: TemplateConfig) => void;
}) {
    const [open, setOpen] = useState<string>('header');
    const picker = useRef<HTMLInputElement>(null);

    /* The tab strip opens the section it names rather than replacing the page. */
    useEffect(() => {
        const first = SECTIONS.find((section) => section.group === tab);
        if (first) setOpen(first.id);
    }, [tab]);

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

    /* ------------------------------------------------------------ bodies */

    function letterhead() {
        const fromOrganization = config.header.logo_source === 'organization';

        /*
         * The card is a picture of the letterhead, so it shows what the
         * letterhead will say — not the token standing in for it. Anything
         * still unresolved falls back to the organisation's own name rather
         * than printing braces at somebody.
         */
        const fallback = organizationName || 'Your clinic';
        const resolved = (config.header.legal_name || '')
            .replace(/\{\{\s*organization_name\s*\}\}/g, fallback)
            .trim();
        const shownName = resolved === '' || resolved.includes('{{') ? fallback : resolved;

        /* What the card says under the name, which is the inheritance rule
           stated plainly rather than left for somebody to work out. */
        const caption = fromOrganization
            ? 'The organisation’s mark'
            : logo?.own
              ? 'This branch’s own mark'
              : 'Inherited from the organisation';

        return (
            <>
                <Toggle
                    label="Show logo"
                    checked={config.header.show_logo}
                    disabled={off('header.show_logo')}
                    onChange={(v) => set('header', 'show_logo', v)}
                />

                {config.header.show_logo && (
                    <div className="row g-3">
                        <div className="col-md-7">
                            {/*
                                THE LOGO IS NOT UPLOADED HERE, and the panel
                                says so rather than offering a button that
                                would have to explain itself afterwards. What
                                prints is the branch's own mark, or the
                                organisation's — both live on those records,
                                and a second copy kept per-template is a second
                                thing to keep in step.
                            */}
                            <div className="dt-logo-box">
                                <div className="dt-logo-preview">
                                    {logo?.url ? (
                                        <img className="dt-logo-img" src={logo.url} alt="" />
                                    ) : (
                                        <span className="dt-logo-fallback">
                                            <i className="ti ti-photo" aria-hidden="true" />
                                        </span>
                                    )}
                                    <div>
                                        <div className="dt-logo-name" title={shownName}>
                                            {shownName}
                                        </div>
                                        <small className="dt-form-hint">{caption}</small>
                                    </div>
                                </div>

                                {/* Changed here, saved on the branch. The file
                                    never lands on the template — see the note
                                    on the endpoint. */}
                                <input
                                    ref={picker}
                                    type="file"
                                    className="d-none"
                                    accept="image/png,image/jpeg,image/webp"
                                    onChange={(e) => {
                                        const file = e.target.files?.[0];
                                        if (file) logo?.onPick(file);
                                        /* So picking the same file twice still fires. */
                                        e.target.value = '';
                                    }}
                                />

                                <div className="dt-logo-actions">
                                    <button
                                        type="button"
                                        className="dt-btn-outline"
                                        disabled={!logo?.canChange || logo.busy}
                                        onClick={() => picker.current?.click()}
                                    >
                                        <i
                                            className={
                                                logo?.busy ? 'ti ti-loader-2' : 'ti ti-upload'
                                            }
                                            aria-hidden="true"
                                        />
                                        {logo?.busy
                                            ? 'Uploading…'
                                            : logo?.own
                                              ? 'Change logo'
                                              : 'Upload logo'}
                                    </button>

                                    {logo?.own && (
                                        <button
                                            type="button"
                                            className="dt-btn-danger-outline"
                                            disabled={!logo.canChange || logo.busy}
                                            onClick={() => logo.onRemove()}
                                        >
                                            <i className="ti ti-trash" aria-hidden="true" />
                                            Remove
                                        </button>
                                    )}
                                </div>

                                {logo?.note && (
                                    <small className="dt-form-hint">
                                        <i className="ti ti-info-circle me-1" aria-hidden="true" />
                                        {logo.note}
                                    </small>
                                )}
                            </div>
                        </div>

                        <div className="col-md-5 d-flex flex-column gap-2">
                            <div className="dt-form-group">
                                <Label text="Which logo" locked={isLocked('header.logo_source')} />
                                <select
                                    className="dt-input"
                                    value={config.header.logo_source}
                                    disabled={off('header.logo_source')}
                                    onChange={(e) => set('header', 'logo_source', e.target.value)}
                                >
                                    <option value="branch">This branch&rsquo;s</option>
                                    <option value="organization">The organisation&rsquo;s</option>
                                    <option value="none">None</option>
                                </select>
                            </div>

                            <div className="dt-form-group">
                                <Label
                                    text="Logo position"
                                    locked={isLocked('header.logo_position')}
                                />
                                <select
                                    className="dt-input"
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
                    </div>
                )}

                <div className="row g-3">
                    <div className="col-md-6">
                        <Field
                            label="Document title"
                            value={config.header.title}
                            locked={isLocked('header.title')}
                            disabled={off('header.title')}
                            onChange={(v) => set('header', 'title', v)}
                            hint="Printed in the coloured band — e.g. “Invoice”, “Receipt”."
                        />
                    </div>
                    <div className="col-md-6">
                        <Field
                            label="Line under the title"
                            value={config.header.subtitle}
                            locked={isLocked('header.subtitle')}
                            disabled={off('header.subtitle')}
                            onChange={(v) => set('header', 'subtitle', v)}
                            hint="Optional — e.g. “Consultation · Care · Better health”."
                        />
                    </div>
                </div>

                <div className="row g-3">
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
                            label="Line under the clinic name"
                            value={config.header.department}
                            locked={isLocked('header.department')}
                            disabled={off('header.department')}
                            onChange={(v) => set('header', 'department', v)}
                            hint="The branch, or a department — e.g. “Pharmacy”, “Laboratory”."
                        />
                    </div>
                </div>

                <div className="row g-3">
                    <div className="col-md-6">
                        <Field
                            label="Clinic tagline"
                            value={config.header.tagline}
                            locked={isLocked('header.tagline')}
                            disabled={off('header.tagline')}
                            onChange={(v) => set('header', 'tagline', v)}
                            hint="Optional — e.g. “Better care. Healthier tomorrow.”"
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
                    label="Contact lines"
                    hint="One fact per row — address, phone, email. Each prints with its own icon, so keep them apart rather than running them together."
                    addLabel="Add contact line"
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
            </>
        );
    }

    function patientAndVisit() {
        return (
            <>
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
            </>
        );
    }

    function invoiceDetails() {
        return (
            <>
                {config.body.tables.length > 0 && (
                    <div className="dt-form-group">
                        <Label text="Table heading" />
                        {config.body.tables.map((table, index) => (
                            <input
                                key={table.token}
                                type="text"
                                className="dt-input mb-1"
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
                        <small className="dt-form-hint">
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
            </>
        );
    }

    function footer() {
        return (
            <>
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
                    hint="The first line is set large and in the accent colour; anything after it prints as small print beneath."
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
            </>
        );
    }

    function paperAndPrint() {
        return (
            <>
                <div className="row g-3">
                    <div className="col-4">
                        <div className="dt-form-group">
                            <Label text="Paper" locked={isLocked('layout.paper')} />
                            <select
                                className="dt-input"
                                value={config.layout.paper}
                                disabled={off('layout.paper')}
                                onChange={(e) => set('layout', 'paper', e.target.value)}
                            >
                                <option value="a4">A4</option>
                                <option value="a5">A5</option>
                                <option value="receipt">Till roll (80mm)</option>
                            </select>
                        </div>
                    </div>
                    <div className="col-4">
                        <div className="dt-form-group">
                            <Label text="Type size (pt)" />
                            <input
                                type="number"
                                min={7}
                                max={16}
                                className="dt-input"
                                value={config.layout.font_size}
                                disabled={off('layout.font_size')}
                                onChange={(e) =>
                                    set('layout', 'font_size', parseInt(e.target.value, 10) || 11)
                                }
                            />
                        </div>
                    </div>
                    <div className="col-4">
                        <div className="dt-form-group">
                            <Label text="Accent" locked={isLocked('layout.accent')} />
                            <input
                                type="color"
                                className="dt-input dt-input-color"
                                value={config.layout.accent}
                                disabled={off('layout.accent')}
                                onChange={(e) => set('layout', 'accent', e.target.value)}
                            />
                        </div>
                    </div>
                </div>

                <div className="dt-form-group">
                    <Label text="Margins (mm)" />
                    <div className="row g-2">
                        {(['top', 'right', 'bottom', 'left'] as const).map((side) => (
                            <div className="col-3" key={side}>
                                <input
                                    type="number"
                                    min={0}
                                    max={40}
                                    className="dt-input w-100"
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
                                <small className="dt-form-hint text-capitalize">{side}</small>
                            </div>
                        ))}
                    </div>
                </div>
            </>
        );
    }

    function permissions() {
        return (
            <>
                <p className="dt-form-hint mb-0">
                    {lockedPaths.length === 0
                        ? 'Nothing on this template is locked. A branch may change every setting on it.'
                        : 'The settings below are locked by your organisation. They show with a lock beside them and cannot be changed here.'}
                </p>

                {/* The locks panel itself is the organisation's to set, and
                    appears only on its own default for somebody who may. */}
                {locks ? (
                    <TemplateLocks bare templateId={locks.templateId} current={locks.current} />
                ) : (
                    lockedPaths.length > 0 && (
                        <ul className="mb-0 fs-13">
                            {lockedPaths.map((path) => (
                                <li key={path}>
                                    <code>{path}</code>
                                </li>
                            ))}
                        </ul>
                    )
                )}
            </>
        );
    }

    /* ---------------------------------------------------------- assembly */

    return (
        <>
            {SECTIONS.map((section) => (
                <Section
                    key={section.id}
                    id={section.id}
                    title={section.title}
                    hint={section.hint}
                    icon={section.icon}
                    open={open}
                    onToggle={setOpen}
                >
                    {section.id === 'header' && letterhead()}
                    {section.id === 'patient' && patientAndVisit()}
                    {section.id === 'body' && invoiceDetails()}
                    {section.id === 'footer' && footer()}
                    {section.id === 'layout' && paperAndPrint()}
                    {section.id === 'permissions' && permissions()}
                    {section.id === 'placeholders' && (
                        <PlaceholderList placeholders={placeholders} />
                    )}
                </Section>
            ))}
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
    children,
}: {
    id: string;
    title: string;
    hint: string;
    icon: string;
    open: string;
    onToggle: (id: string) => void;
    children: React.ReactNode;
}) {
    const isOpen = open === id;

    return (
        <div className={`dt-accordion-card ${isOpen ? 'is-open' : ''}`}>
            <button
                type="button"
                className="dt-accordion-trigger"
                aria-expanded={isOpen}
                onClick={() => onToggle(isOpen ? '' : id)}
            >
                <div className="dt-accordion-header-left">
                    <i className={`dt-accordion-icon ${icon}`} aria-hidden="true" />
                    <div>
                        <span className="dt-accordion-title">{title}</span>
                        <span className="dt-accordion-hint">{hint}</span>
                    </div>
                </div>
                <i
                    className={`dt-accordion-chevron ti ti-chevron-${isOpen ? 'up' : 'down'}`}
                    aria-hidden="true"
                />
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
                className="dt-input dt-textarea"
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
        <label className="dt-toggle form-check form-switch">
            <input
                type="checkbox"
                className="form-check-input"
                checked={checked}
                disabled={disabled}
                onChange={(e) => onChange(e.target.checked)}
            />
            <span className="form-check-label">{label}</span>
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
                    {/* Quiet until wanted: a row of red boxes down the side of
                        the address reads as errors, not as controls. */}
                    <button
                        type="button"
                        className="dt-line-remove"
                        disabled={disabled}
                        title="Remove line"
                        aria-label="Remove line"
                        onClick={() => onChange(value.filter((_, i) => i !== index))}
                    >
                        <i className="ti ti-trash" aria-hidden="true" />
                    </button>
                </div>
            ))}

            <div>
                <button
                    type="button"
                    className="dt-add-line-btn"
                    disabled={disabled}
                    onClick={() => onChange([...value, ''])}
                >
                    <i className="ti ti-plus" aria-hidden="true" />
                    {addLabel}
                </button>
            </div>

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
        <>
            <p className="dt-form-hint mb-0">
                Click one to copy, then paste it into any text field. It is replaced with the real
                value when the document is printed.
            </p>

            {Object.entries(groups).map(([group, entries]) => (
                <div key={group}>
                    <div className="dt-form-label text-capitalize mb-1">{group}</div>
                    <div className="d-flex flex-wrap gap-1">
                        {entries.map((placeholder) => {
                            const token = `{{${placeholder.token}}}`;

                            return (
                                <button
                                    key={placeholder.token}
                                    type="button"
                                    className={`dt-chip ${copied === token ? 'is-copied' : ''}`}
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
        </>
    );
}
