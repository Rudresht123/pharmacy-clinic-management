import { useEffect, useMemo, useRef, useState } from 'react';
import { FormModal } from '@/shared/components/ui/FormModal';
import { Button } from '@/shared/components/ui/Button';
import { LoadingBlock } from '@/shared/components/ui/Feedback';
import { TextField } from '@/shared/components/form/Fields';
import { useApiForm } from '@/shared/components/form/useApiForm';
import { resolveErrorMessage } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import { useRemoveVariant, useSaveVariant, useTemplateVariants } from '../api';
import { PLACEHOLDERS } from '../placeholders';
import type { Channel, MessageTemplate } from '../types';

interface Values {
    subject: string;
    content: string;
}

/**
 * A branch's own wording of one template.
 *
 * What `communication.branch_templates` opens. It writes the branch's copy and
 * never the template itself: other branches go on sending the organisation's
 * wording, and "Use the organisation's wording" brings this one back to it.
 *
 * Email and App notifications only. WhatsApp sends what the provider approved,
 * so its page never offers this.
 */
export function BranchWordingModal({
    template,
    channel,
    onClose,
}: {
    /** Null keeps it closed. */
    template: MessageTemplate | null;
    channel: Channel;
    onClose: () => void;
}) {
    const open = template !== null;
    const { data, isLoading } = useTemplateVariants(template?.id);
    const save = useSaveVariant();
    const remove = useRemoveVariant();

    const branches = useMemo(() => (data?.branches ?? []).filter((item) => item.can_edit), [data]);

    // Somebody working at more than one branch says which; everybody else never sees the choice.
    const [locationId, setLocationId] = useState<number | null>(null);
    const branch = branches.find((item) => item.location_id === locationId) ?? branches[0] ?? null;

    const bodyRef = useRef<HTMLTextAreaElement | null>(null);

    const limit = channel === 'push' ? 500 : 5000;

    const {
        register,
        handleSubmit,
        reset,
        submit,
        watch,
        setValue,
        formState: { errors, isSubmitting },
    } = useApiForm<Values>({ defaultValues: { subject: '', content: '' } });

    useEffect(() => {
        if (!template) {
            return;
        }

        reset({
            subject: branch?.variant?.subject ?? template.org_subject ?? template.subject ?? '',
            content: branch?.variant?.content ?? template.org_content ?? template.content,
        });
    }, [template, branch?.location_id, branch?.variant, reset]);

    const content = watch('content') ?? '';
    const body = register('content');

    /** At the cursor, as on the template form: a name belongs where the caret is. */
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

    const onSubmit = handleSubmit(async (values) => {
        if (!template || !branch) {
            return;
        }

        const saved = await submit(values, () =>
            save.mutateAsync({
                templateId: template.id,
                locationId: branch.location_id,
                subject: values.subject.trim() || null,
                content: values.content.trim(),
                is_active: true,
            }),
        );

        if (saved) {
            onClose();
        }
    });

    async function useOrganisationWording() {
        if (!template || !branch) {
            return;
        }

        try {
            await remove.mutateAsync({ templateId: template.id, locationId: branch.location_id });
            onClose();
        } catch (error) {
            notify.error(resolveErrorMessage(error));
        }
    }

    const bodyError = errors.content?.message;
    const nobody = !isLoading && branch === null;

    return (
        <FormModal
            open={open}
            onClose={onClose}
            title={template ? `Edit ${template.name}` : 'Edit template'}
            subtitle={
                branch
                    ? `Only ${branch.name} sends this wording.`
                    : 'Your branch’s own wording'
            }
            icon={<i className="ti ti-template" />}
            size="md"
            onSubmit={onSubmit}
            submitting={isSubmitting || save.isPending}
            submitLabel="Save for this branch"
        >
            {isLoading ? (
                <LoadingBlock label="Loading wording…" />
            ) : nobody ? (
                <p className="pd-quiet">
                    <i className="ti ti-lock" aria-hidden="true" />
                    You do not work at a branch whose wording you may change.
                </p>
            ) : (
                <>
                    {branches.length > 1 && (
                        <div className="mb-3">
                            <label className="form-label" htmlFor="wording-branch">
                                Branch
                            </label>
                            <select
                                id="wording-branch"
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
                        label={channel === 'push' ? 'Title' : 'Subject'}
                        register={register}
                        errors={errors}
                    />

                    <div className="mb-3">
                        <label className="form-label comm-field-label" htmlFor="wording-content">
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
                            id="wording-content"
                            rows={5}
                            maxLength={limit}
                            className={`form-control${bodyError ? ' is-invalid' : ''}`}
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

                    {branch?.variant && (
                        <Button
                            variant="light"
                            size="sm"
                            icon="ti ti-arrow-back-up"
                            loading={remove.isPending}
                            onClick={useOrganisationWording}
                        >
                            Use the organisation’s wording
                        </Button>
                    )}
                </>
            )}
        </FormModal>
    );
}
