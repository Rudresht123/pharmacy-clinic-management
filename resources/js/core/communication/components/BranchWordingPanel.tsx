import { useEffect, useState } from 'react';
import { Button } from '@/shared/components/ui/Button';
import { Card } from '@/shared/components/ui/Card';
import { LoadingBlock } from '@/shared/components/ui/Feedback';
import { getValidationErrors, resolveErrorMessage } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import { useRemoveVariant, useSaveVariant, useTemplateVariants, type BranchWording } from '../api';
import type { MessageTemplate } from '../types';

/**
 * One branch's wording, edited in place. Starts from the branch's own text
 * when it has some, otherwise from the organisation's, so it never opens on
 * a blank.
 */
function Editor({
    template,
    branch,
    titled,
    onDone,
}: {
    template: MessageTemplate;
    branch: BranchWording;
    titled: boolean;
    onDone: () => void;
}) {
    const save = useSaveVariant();
    const [subject, setSubject] = useState(branch.variant?.subject ?? template.subject ?? '');
    const [content, setContent] = useState(branch.variant?.content ?? template.content);
    const [active, setActive] = useState(branch.variant?.is_active ?? true);
    const [errors, setErrors] = useState<Record<string, string[]>>({});

    async function onSave() {
        setErrors({});

        try {
            await save.mutateAsync({
                templateId: template.id,
                locationId: branch.location_id,
                subject: titled ? subject.trim() || null : null,
                content: content.trim(),
                is_active: active,
            });
            onDone();
        } catch (error) {
            const found = getValidationErrors(error);

            if (found) {
                setErrors(found);
            } else {
                notify.error(resolveErrorMessage(error));
            }
        }
    }

    return (
        <div className="bw-editor">
            {titled && (
                <>
                    <label className="form-label" htmlFor={`bw-subject-${branch.location_id}`}>
                        {template.channel === 'push' ? 'Title' : 'Subject'}
                    </label>
                    <input
                        id={`bw-subject-${branch.location_id}`}
                        className={`form-control${errors.subject ? ' is-invalid' : ''}`}
                        value={subject}
                        maxLength={255}
                        onChange={(event) => setSubject(event.target.value)}
                    />
                    {errors.subject && <div className="invalid-feedback d-block">{errors.subject[0]}</div>}
                </>
            )}

            <label className="form-label mt-2" htmlFor={`bw-content-${branch.location_id}`}>
                Message
            </label>
            <textarea
                id={`bw-content-${branch.location_id}`}
                className={`form-control${errors.content ? ' is-invalid' : ''}`}
                rows={3}
                maxLength={template.channel === 'push' ? 500 : 5000}
                value={content}
                onChange={(event) => setContent(event.target.value)}
            />
            {errors.content && <div className="invalid-feedback d-block">{errors.content[0]}</div>}

            <div className="bw-actions">
                <div className="form-check form-switch m-0">
                    <input
                        className="form-check-input"
                        type="checkbox"
                        role="switch"
                        id={`bw-active-${branch.location_id}`}
                        checked={active}
                        onChange={() => setActive((current) => !current)}
                    />
                    <label className="form-check-label" htmlFor={`bw-active-${branch.location_id}`}>
                        Use this wording
                    </label>
                </div>

                <div className="d-flex gap-2">
                    <Button variant="light" size="sm" onClick={onDone}>
                        Cancel
                    </Button>
                    <Button size="sm" icon="ti ti-device-floppy" loading={save.isPending} onClick={() => void onSave()}>
                        Save
                    </Button>
                </div>
            </div>
        </div>
    );
}

/**
 * Global → Organisation → Branch, for one template.
 *
 * The template itself is the organisation's wording (and began as the
 * platform's default). Each branch either sends that, or its own — what a
 * patient booked at Gurgaon is told can say "Gurgaon". Who may reword which
 * branch is the server's answer (`can_edit`); a row this person may not
 * change shows its state and no button.
 */
export function BranchWordingPanel({ template }: { template: MessageTemplate | null }) {
    const { data, isLoading } = useTemplateVariants(template?.id);
    const remove = useRemoveVariant();
    const [editing, setEditing] = useState<number | null>(null);

    useEffect(() => setEditing(null), [template?.id]);

    if (!template) {
        return null;
    }

    const titled = template.channel === 'email' || template.channel === 'push';

    return (
        <Card
            className="comm-card"
            title="Wording per branch"
            icon="ti ti-building-store"
            description="Each branch sends the organisation’s wording unless it has its own."
        >
            {isLoading || !data ? (
                <LoadingBlock />
            ) : !data.rewordable ? (
                <p className="comm-settings-copy mb-0">
                    WhatsApp sends exactly the wording it approved, so a branch cannot reword this template.
                </p>
            ) : data.branches.length === 0 ? (
                <p className="comm-settings-copy mb-0">No branches to show.</p>
            ) : (
                <ul className="bw-list">
                    {data.branches.map((branch) => (
                        <li key={branch.location_id}>
                            <div className="bw-row">
                                <span className="bw-name">
                                    <b>{branch.name}</b>
                                    {branch.variant ? (
                                        <span className={`comm-pill ${branch.variant.is_active ? 'is-live' : 'is-muted'}`}>
                                            {branch.variant.is_active ? 'Own wording' : 'Own wording, switched off'}
                                        </span>
                                    ) : (
                                        <span className="comm-pill is-muted">Organisation’s wording</span>
                                    )}
                                </span>

                                {branch.can_edit && editing !== branch.location_id && (
                                    <div className="d-flex gap-2">
                                        {branch.variant && (
                                            <Button
                                                variant="light"
                                                size="sm"
                                                loading={remove.isPending}
                                                onClick={() =>
                                                    remove.mutate({ templateId: template.id, locationId: branch.location_id })
                                                }
                                            >
                                                Use organisation’s
                                            </Button>
                                        )}
                                        <Button variant="light" size="sm" onClick={() => setEditing(branch.location_id)}>
                                            {branch.variant ? 'Edit' : 'Reword'}
                                        </Button>
                                    </div>
                                )}
                            </div>

                            {branch.variant && editing !== branch.location_id && (
                                <p className="bw-text">{branch.variant.content}</p>
                            )}

                            {editing === branch.location_id && (
                                <Editor
                                    template={template}
                                    branch={branch}
                                    titled={titled}
                                    onDone={() => setEditing(null)}
                                />
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}
