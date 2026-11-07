import { useEffect, useMemo, useRef, useState } from 'react';
import { Button } from '@/shared/components/ui/Button';
import { TextField } from '@/shared/components/form/Fields';
import { useApiForm } from '@/shared/components/form/useApiForm';
import {
    useForgetRuleRecipients,
    useRemoveVariant,
    useRuleRecipientOptions,
    useSaveRuleRecipients,
    useSaveTemplate,
    useSaveVariant,
    useTemplateVariants,
} from '../api';
import { PLACEHOLDERS } from '../placeholders';
import {
    NO_RECIPIENTS,
    RECIPIENT_LISTS,
    fromRecipientValues,
    recipientListError,
    recipientOptions,
    sameRecipients,
    toRecipientValues,
    type RecipientValues,
} from '../recipientForm';
import { htmlToText, isPlainEnoughToEdit, textToHtml } from '../templateText';
import { RecipientPills, type PickOption } from './RecipientPills';
import { WhoGetsIt } from './WhoGetsIt';
import type { AutomationRule, MessageTemplate } from '../types';

interface Values extends RecipientValues {
    subject: string;
    content: string;
}

/** The jobs a clinic chooses between. Anybody else is under Custom selection. */
const PLACES: PickOption[] = [
    { value: 'patient', label: 'Patient' },
    { value: 'doctor', label: 'Doctor', hint: 'The doctor the appointment is with' },
    { value: 'branch_manager', label: 'Branch manager' },
    { value: 'front_desk', label: 'Receptionist' },
];

/**
 * A template opened on its own row: what it says on one side, and To, CC and BCC
 * on the other, with one Save for both. Nothing opens over the list.
 *
 * Whose copy it writes depends on who is asking. Whoever manages communication
 * rewrites the template. Somebody at one branch writes that branch's own wording, and
 * the server keeps their recipients to that branch too. Only what changed is saved:
 * choosing who gets a message must not freeze a copy of wording nobody touched.
 *
 * Addresses typed in on the Recipients screen are not shown as boxes here, and pass
 * through a save untouched.
 */
export function TemplateRowEditor({
    channel,
    template,
    rules,
    canChoose,
    canManage,
    canReword,
    onClose,
}: {
    channel: 'email' | 'push';
    template: MessageTemplate;
    /** The rules that send this template. */
    rules: AutomationRule[];
    canChoose: boolean;
    canManage: boolean;
    canReword: boolean;
    onClose: () => void;
}) {
    const byEmail = channel === 'email';
    const rule = rules[0] ?? null;
    const lists = RECIPIENT_LISTS[channel];
    const choosing = canChoose && rule !== null;
    const forOneBranch = !canManage && canReword;

    const saveTemplate = useSaveTemplate(channel);
    const saveVariant = useSaveVariant();
    const removeVariant = useRemoveVariant();
    const saveRecipients = useSaveRuleRecipients();
    const forgetRecipients = useForgetRuleRecipients();
    const choices = useRuleRecipientOptions(choosing);

    const { data: variants } = useTemplateVariants(forOneBranch ? template.id : undefined);
    const branches = useMemo(() => (variants?.branches ?? []).filter((item) => item.can_edit), [variants]);

    // Somebody working at more than one branch says which; everybody else never sees the choice.
    const [locationId, setLocationId] = useState<number | null>(null);
    const branch = branches.find((item) => item.location_id === locationId) ?? branches[0] ?? null;

    /*
     * A branch's wording is read on opening. Until it arrives the words stay locked:
     * what is on screen may yet change, and a Save then would have no branch to go to.
     */
    const loadingBranch = forOneBranch && variants === undefined;
    const wording = canManage || (canReword && (loadingBranch || branch !== null));

    const stored = (forOneBranch ? branch?.variant?.content : null) ?? template.org_content ?? template.content;
    const asText = byEmail && isPlainEnoughToEdit(stored);

    const saved: Values = useMemo(
        () => ({
            subject: (forOneBranch ? branch?.variant?.subject : null) ?? template.org_subject ?? template.subject ?? '',
            content: asText ? htmlToText(stored) : stored,
            ...(rule ? toRecipientValues(rule.recipients) : NO_RECIPIENTS),
        }),
        [forOneBranch, branch?.variant, template, rule, asText, stored],
    );

    const {
        register,
        control,
        handleSubmit,
        reset,
        submit,
        watch,
        setValue,
        formState: { errors, isSubmitting },
    } = useApiForm<Values>({ defaultValues: saved });

    // By what it says, not by identity: a list refetched behind the form must not wipe what is being typed.
    const savedKey = JSON.stringify(saved);

    useEffect(() => {
        reset(saved);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [savedKey, reset]);

    const values = watch();
    const content = values.content ?? '';
    const limit = byEmail ? 5000 : 500;

    const reworded =
        wording && ((values.subject ?? '').trim() !== saved.subject.trim() || content.trim() !== saved.content.trim());
    const rechosen = choosing && rule !== null && !sameRecipients(fromRecipientValues(values, byEmail), rule.recipients);

    const bodyRef = useRef<HTMLTextAreaElement | null>(null);
    const body = register('content');

    /** At the cursor: a name belongs where the caret is, not at the end of the message. */
    function insert(token: string) {
        const field = bodyRef.current;
        const start = field?.selectionStart ?? content.length;
        const end = field?.selectionEnd ?? start;

        setValue('content', `${content.slice(0, start)}${token}${content.slice(end)}`, { shouldDirty: true });

        window.requestAnimationFrame(() => {
            field?.focus();
            field?.setSelectionRange(start + token.length, start + token.length);
        });
    }

    const onSubmit = handleSubmit(async (chosen) => {
        const message = asText ? textToHtml(chosen.content) : chosen.content.trim();

        const done = await submit(chosen, async () => {
            if (reworded && canManage) {
                await saveTemplate.mutateAsync({
                    id: template.id,
                    channel,
                    name: template.name,
                    category: template.category,
                    template_type: template.template_type,
                    subject: chosen.subject.trim() || null,
                    content: message,
                    status: template.status,
                    icon: template.icon,
                    tone: template.tone,
                    audience: template.audience,
                });
            } else if (reworded && branch) {
                await saveVariant.mutateAsync({
                    templateId: template.id,
                    locationId: branch.location_id,
                    subject: chosen.subject.trim() || null,
                    content: message,
                    is_active: true,
                });
            }

            if (rechosen) {
                const recipients = fromRecipientValues(chosen, byEmail);

                // One choice for every rule that sends it, and one message saying so.
                for (const [index, each] of rules.entries()) {
                    await saveRecipients.mutateAsync({ id: each.id, recipients, quiet: reworded || index > 0 });
                }
            }

            return true;
        });

        if (done) {
            onClose();
        }
    });

    // Staff wording never reaches a patient, so that is not offered on a staff alert.
    const places = PLACES.filter((place) => rule?.audience !== 'staff' || place.value !== 'patient');

    const staff: PickOption[] = (choices.data?.staff ?? []).map((person) => ({
        value: `user:${person.id}`,
        label: byEmail && !person.has_email ? `${person.name} (no email)` : person.name,
    }));

    // What to call a pill: these names, or for anybody saved earlier and no longer
    // offered here (a whole role, Booked by) the name the server gave them.
    const names: Record<string, string> = Object.fromEntries(
        [...(rule ? recipientOptions(rule, choices.data) : []), ...staff, ...PLACES].map((option) => [option.value, option.label]),
    );
    const typed = rule
        ? [...rule.recipients.to, ...rule.recipients.cc, ...rule.recipients.bcc].filter((entry) => entry.type === 'email')
        : [];

    const bodyError = errors.content?.message;
    const busy = isSubmitting || saveTemplate.isPending || saveVariant.isPending || saveRecipients.isPending;

    return (
        <form className="rcp-editor" onSubmit={onSubmit} noValidate>
            {wording && (
                <section className="rcp-editor-words">
                    <h6>
                        <i className="ti ti-pencil" aria-hidden="true" />
                        What it says
                        {forOneBranch && branch && <small>For {branch.name} only</small>}
                    </h6>

                    <fieldset className="rcp-fields" disabled={loadingBranch}>
                    {forOneBranch && branches.length > 1 && (
                        <div className="mb-3">
                            <label className="form-label" htmlFor={`wording-branch-${template.id}`}>
                                Branch
                            </label>
                            <select
                                id={`wording-branch-${template.id}`}
                                className="form-select"
                                value={branch?.location_id ?? ''}
                                onChange={(event) => setLocationId(Number(event.target.value))}
                            >
                                {branches.map((item) => (
                                    <option key={item.location_id} value={item.location_id}>
                                        {item.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                    )}

                    <TextField
                        name="subject"
                        label={byEmail ? 'Subject' : 'Title'}
                        register={register}
                        errors={errors}
                    />

                    <div className="mb-2">
                        <label className="form-label comm-field-label" htmlFor={`wording-content-${template.id}`}>
                            <span>
                                Message
                                <span className="text-danger ms-1">*</span>
                            </span>

                            <span className={`comm-count-hint${content.length > limit * 0.88 ? ' is-near' : ''}`}>
                                {content.length}/{limit}
                            </span>
                        </label>

                        <textarea
                            {...body}
                            id={`wording-content-${template.id}`}
                            rows={byEmail ? 9 : 4}
                            maxLength={limit}
                            className={`form-control${bodyError ? ' is-invalid' : ''}`}
                            ref={(element) => {
                                body.ref(element);
                                bodyRef.current = element;
                            }}
                        />

                        {bodyError && <div className="invalid-feedback d-block">{bodyError}</div>}
                    </div>

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
                    </fieldset>
                </section>
            )}

            <section className="rcp-editor-who">
                {choosing ? (
                    <>
                        <h6>
                            <i className="ti ti-users" aria-hidden="true" />
                            Who gets it
                        </h6>

                        <div className="rcp-lists">
                            {lists.map((list) => (
                                <RecipientPills<Values>
                                    key={list.field}
                                    name={`recipients.${list.field}`}
                                    label={list.label}
                                    control={control}
                                    places={places}
                                    staff={staff}
                                    names={names}
                                    takenOn={Object.fromEntries(
                                        lists
                                            .filter((other) => other.field !== list.field)
                                            .flatMap((other) =>
                                                (values.recipients?.[other.field] ?? []).map((value) => [value, other.label]),
                                            ),
                                    )}
                                    loading={choices.isLoading}
                                    error={recipientListError(errors.recipients, list.field)}
                                />
                            ))}
                        </div>

                        <p className="rcp-editor-note">
                            <i className="ti ti-info-circle" aria-hidden="true" />
                            <span>
                                {byEmail
                                    ? 'To and CC can see each other’s address. BCC stays hidden.'
                                    : 'Each person gets it on every phone they are signed in on.'}
                                {typed.length > 0 && <> Also goes to {typed.map((entry) => entry.address).join(', ')}.</>}
                            </span>
                        </p>
                    </>
                ) : (
                    <WhoGetsIt channel={channel} rules={rules} />
                )}
            </section>

            <footer className="rcp-editor-foot">
                <span className="rcp-editor-back">
                    {forOneBranch && branch?.variant && (
                        <button
                            type="button"
                            disabled={removeVariant.isPending}
                            onClick={() => removeVariant.mutate({ templateId: template.id, locationId: branch.location_id })}
                        >
                            <i className="ti ti-arrow-back-up" aria-hidden="true" />
                            Use the organisation’s wording
                        </button>
                    )}

                    {forOneBranch && rule?.branch_own && (
                        <button
                            type="button"
                            disabled={forgetRecipients.isPending}
                            onClick={() => rules.forEach((each) => forgetRecipients.mutate(each.id))}
                        >
                            <i className="ti ti-arrow-back-up" aria-hidden="true" />
                            Use the organisation’s choice
                        </button>
                    )}
                </span>

                <Button
                    variant="light"
                    size="sm"
                    disabled={busy}
                    onClick={() => {
                        reset(saved);
                        onClose();
                    }}
                >
                    Cancel
                </Button>

                <Button type="submit" size="sm" icon="ti ti-check" loading={busy} disabled={!reworded && !rechosen}>
                    {forOneBranch ? 'Save for this branch' : 'Save'}
                </Button>
            </footer>
        </form>
    );
}
