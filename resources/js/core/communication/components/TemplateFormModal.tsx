import { useEffect, useRef } from 'react';
import { FormModal } from '@/shared/components/ui/FormModal';
import { SelectField, TextField } from '@/shared/components/form/Fields';
import { useApiForm } from '@/shared/components/form/useApiForm';
import { PLACEHOLDERS } from '../placeholders';
import { TemplatePreview } from './TemplatePreview';
import type { Channel, MessageTemplate, TemplateStatus } from '../types';

/** The tints a template may carry, as the list screens read them. */
const TONES = ['sky', 'violet', 'emerald', 'rose', 'amber', 'teal'] as const;

/** Enough to tell a dozen templates apart at a glance, and no more. */
const ICONS = [
    'ti ti-calendar-check',
    'ti ti-bell',
    'ti ti-calendar-repeat',
    'ti ti-circle-x',
    'ti ti-file-invoice',
    'ti ti-heart',
    'ti ti-receipt-2',
    'ti ti-flask',
    'ti ti-pill',
    'ti ti-users',
    'ti ti-message',
    'ti ti-clock-hour-4',
] as const;

interface Values {
    name: string;
    category: string;
    template_type: string;
    subject: string;
    content: string;
    status: TemplateStatus;
    icon: string;
    tone: string;
}

/**
 * What "Type" may be, per channel.
 *
 * The two lists do not overlap on their first entry, which is why this is a
 * lookup rather than one default: WhatsApp classifies a template as Utility or
 * Marketing because its own approval process does, while email has no such
 * process and the distinction that matters is whether a patient may opt out.
 */
const TYPES: Record<Channel, { value: string; label: string }[]> = {
    whatsapp: [
        { value: 'Utility', label: 'Utility' },
        { value: 'Marketing', label: 'Marketing' },
    ],
    email: [
        { value: 'Transactional', label: 'Transactional' },
        { value: 'Marketing', label: 'Marketing' },
    ],
    sms: [
        { value: 'Transactional', label: 'Transactional' },
        { value: 'Marketing', label: 'Marketing' },
    ],
};

const EMPTY: Omit<Values, 'template_type'> = {
    name: '',
    category: 'Appointment',
    subject: '',
    content: '',
    status: 'draft',
    icon: 'ti ti-calendar-check',
    tone: 'sky',
};

/**
 * Write a template, and see it as the patient will.
 *
 * The preview is not decoration. A template is mostly placeholders, and
 * `Hi {{patient_name}}, your appointment with {{doctor_name}} is on {{date}}`
 * cannot be read as a sentence — the only way to tell whether it scans is to
 * see it filled in, so it re-renders on every keystroke.
 *
 * The chips insert at the CURSOR rather than appending, because a name belongs
 * at the start of a greeting; appending is how somebody ends up typing the
 * braces by hand and getting them wrong.
 *
 * No client-side rules: the server validates and `submit` maps its errors back
 * onto the fields, which is how every other form here works and keeps the two
 * from disagreeing.
 */
export function TemplateFormModal({
    open,
    channel,
    template,
    categories,
    onClose,
    onSave,
    saving,
}: {
    open: boolean;
    channel: Channel;
    /** Null creates; a template edits it. */
    template: MessageTemplate | null;
    categories: readonly string[];
    onClose: () => void;
    onSave: (values: Partial<MessageTemplate> & { id?: number }) => Promise<unknown>;
    saving?: boolean;
}) {
    const bodyRef = useRef<HTMLTextAreaElement | null>(null);

    const types = TYPES[channel] ?? TYPES.whatsapp;

    /*
     * WhatsApp refuses a template body over 1024 characters. Email has no such
     * limit, and enforcing WhatsApp's on it would silently truncate a letter
     * mid-sentence at the moment somebody pasted it in.
     */
    const limit = channel === 'whatsapp' ? 1024 : 5000;

    const {
        register,
        control,
        handleSubmit,
        reset,
        submit,
        watch,
        setValue,
        formState: { errors, isSubmitting },
    } = useApiForm<Values>({ defaultValues: { ...EMPTY, template_type: types[0].value } });

    useEffect(() => {
        if (!open) {
            return;
        }

        /*
         * A stored type the CHANNEL does not offer falls back to its first.
         * An email template defaulting to WhatsApp's "Utility" left the box
         * reading "Select…" — the value was set, it simply was not on the
         * list, so nothing matched and the field looked empty and broken.
         */
        const stored = template?.template_type ?? '';
        const known = types.some((type) => type.value === stored);

        reset(
            template
                ? {
                      name: template.name,
                      category: template.category,
                      template_type: known ? stored : types[0].value,
                      subject: template.subject ?? '',
                      content: template.content,
                      status: template.status,
                      icon: template.icon ?? EMPTY.icon,
                      tone: template.tone ?? EMPTY.tone,
                  }
                : { ...EMPTY, template_type: types[0].value },
        );
    }, [open, template, types, reset]);

    const content = watch('content');
    const icon = watch('icon');
    const tone = watch('tone');

    // Registered by hand so the caret can be found: react-hook-form owns the
    // ref, and the insert below needs it too.
    const body = register('content');

    /**
     * Drop a placeholder where the cursor is, and put the cursor after it.
     *
     * Restoring the selection is what makes this usable more than once —
     * without it the caret jumps to the end and the second chip lands in the
     * wrong place.
     */
    function insert(token: string) {
        const field = bodyRef.current;

        if (!field) {
            setValue('content', `${content}${token}`, { shouldDirty: true });

            return;
        }

        const start = field.selectionStart ?? content.length;
        const end = field.selectionEnd ?? start;

        setValue('content', `${content.slice(0, start)}${token}${content.slice(end)}`, {
            shouldDirty: true,
        });

        window.requestAnimationFrame(() => {
            field.focus();
            field.setSelectionRange(start + token.length, start + token.length);
        });
    }

    const onSubmit = handleSubmit(async (values) => {
        const result = await submit(values, () =>
            onSave({
                id: template?.id,
                channel,
                name: values.name.trim(),
                category: values.category,
                template_type: values.template_type.trim() || null,
                // WhatsApp has no subject line, and storing a blank is not the
                // same as storing nothing.
                subject: channel === 'email' ? values.subject.trim() || null : null,
                content: values.content.trim(),
                status: values.status,
                icon: values.icon,
                tone: values.tone,
            }),
        );

        if (result) {
            onClose();
        }
    });

    const bodyError = errors.content?.message;

    return (
        <FormModal
            open={open}
            onClose={onClose}
            title={template ? `Edit ${template.name}` : 'New template'}
            subtitle={
                channel === 'email'
                    ? 'Subject and body, as the patient will read them'
                    : 'WhatsApp must approve a template before it can be sent'
            }
            icon={<i className="ti ti-template" />}
            size="xl"
            onSubmit={onSubmit}
            submitting={isSubmitting || saving}
            submitLabel={template ? 'Save changes' : 'Create template'}
        >
            <div className="comm-form">
                <div className="comm-form-fields">
                    <TextField
                        name="name"
                        label="Template name"
                        placeholder="Appointment confirmation"
                        required
                        register={register}
                        errors={errors}
                    />

                    <div className="comm-form-row">
                        <SelectField
                            name="category"
                            label="Category"
                            control={control}
                            errors={errors}
                            options={categories
                                .filter((item) => item !== 'All')
                                .map((item) => ({ value: item, label: item }))}
                        />

                        <SelectField
                            name="template_type"
                            label="Type"
                            control={control}
                            errors={errors}
                            options={types}
                        />
                    </div>

                    {channel === 'email' && (
                        <TextField
                            name="subject"
                            label="Subject"
                            placeholder="Your appointment is confirmed"
                            required
                            register={register}
                            errors={errors}
                        />
                    )}

                    <div className="mb-3">
                        <label className="form-label comm-field-label" htmlFor="content">
                            <span>
                                Message
                                <span className="text-danger ms-1">*</span>
                            </span>

                            {/* A limit rather than trivia on WhatsApp, which
                                refuses a longer body outright — so it goes
                                amber with room still left to fix it. */}
                            <span
                                className={`comm-count-hint${content.length > limit * 0.88 ? ' is-near' : ''}`}
                            >
                                {content.length}/{limit}
                            </span>
                        </label>

                        <textarea
                            {...body}
                            id="content"
                            rows={5}
                            maxLength={limit}
                            className={`form-control${bodyError ? ' is-invalid' : ''}`}
                            placeholder="Hi {{patient_name}}, your appointment with {{doctor_name}} is confirmed for {{date}} at {{time}}."
                            ref={(element) => {
                                body.ref(element);
                                bodyRef.current = element;
                            }}
                        />

                        {bodyError && <div className="invalid-feedback d-block">{bodyError}</div>}

                        <div className="comm-tokens">
                            <span className="comm-tokens-label">Insert</span>

                            {PLACEHOLDERS.map((placeholder) => (
                                <button
                                    key={placeholder.token}
                                    type="button"
                                    className="comm-token"
                                    title={`Becomes "${placeholder.sample}"`}
                                    onClick={() => insert(placeholder.token)}
                                >
                                    {placeholder.label}
                                </button>
                            ))}
                        </div>
                    </div>

                    {/*
                        Icon and colour are one decision — how the row looks in
                        the library — so they sit in one block. Split across a
                        two-column row the twelve icons wrapped 7-then-5 while
                        six swatches sat in acres of space.
                    */}
                    <div className="comm-appearance">
                        <div className="comm-appearance-head">
                            <span className={`comm-glyph is-${tone}`} aria-hidden="true">
                                <i className={icon} />
                            </span>

                            <div>
                                <b>Appearance</b>
                                <small>How this template looks in the list</small>
                            </div>
                        </div>

                        <div className="comm-appearance-pickers">
                            <div>
                                <label className="form-label">Icon</label>

                                <div className="comm-picker is-icons">
                                    {ICONS.map((item) => (
                                        <button
                                            key={item}
                                            type="button"
                                            aria-label={item.replace('ti ti-', '')}
                                            aria-pressed={icon === item}
                                            className={`comm-glyph is-${tone}${icon === item ? ' is-active' : ''}`}
                                            onClick={() =>
                                                setValue('icon', item, { shouldDirty: true })
                                            }
                                        >
                                            <i className={item} />
                                        </button>
                                    ))}
                                </div>
                            </div>

                            <div>
                                <label className="form-label">Colour</label>

                                <div className="comm-picker">
                                    {TONES.map((item) => (
                                        <button
                                            key={item}
                                            type="button"
                                            aria-label={item}
                                            aria-pressed={tone === item}
                                            className={`comm-swatch is-${item}${tone === item ? ' is-active' : ''}`}
                                            onClick={() =>
                                                setValue('tone', item, { shouldDirty: true })
                                            }
                                        />
                                    ))}
                                </div>
                            </div>
                        </div>
                    </div>

                    <SelectField
                        name="status"
                        label="Status"
                        hint="Only an approved template can be sent or automated."
                        control={control}
                        errors={errors}
                        options={[
                            { value: 'draft', label: 'Draft — not ready' },
                            { value: 'pending', label: 'Pending — submitted for approval' },
                            { value: 'approved', label: 'Approved — may be sent' },
                            { value: 'rejected', label: 'Rejected — needs rewriting' },
                        ]}
                    />
                </div>

                <div className="comm-form-preview">
                    <h6 className="comm-subhead">Preview</h6>

                    {/* Channel-shaped. An email previewed as a chat bubble is
                        not a preview — the subject line, which is the only
                        part most people ever read, would not appear at all. */}
                    <TemplatePreview
                        channel={channel}
                        name={watch('name')}
                        subject={watch('subject')}
                        content={content}
                        icon={icon}
                    />

                    <p className="pf-soon">
                        <i className="ti ti-info-circle" aria-hidden="true" />
                        Sample values shown. Real sends fill them from the patient record.
                    </p>
                </div>
            </div>
        </FormModal>
    );
}
