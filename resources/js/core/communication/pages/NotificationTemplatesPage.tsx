import { useEffect, useMemo, useRef, useState } from 'react';
import { Button } from '@/shared/components/ui/Button';
import { Card } from '@/shared/components/ui/Card';
import { LoadingBlock } from '@/shared/components/ui/Feedback';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { getValidationErrors, resolveErrorMessage } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import { locationsHooks } from '@/core/locations/api';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import {
    useNotificationTemplates,
    useResetNotificationTemplate,
    useSaveNotificationTemplate,
    type NotificationTypeRow,
    type TemplateLevel,
    type TemplateSource,
} from '../notifications';

/** Where the wording on the phone came from, said plainly. */
const SOURCE: Record<TemplateSource, { label: string; tone: string }> = {
    branch: { label: 'This branch', tone: 'is-live' },
    organization: { label: 'Organisation', tone: 'is-read' },
    global: { label: 'Platform default', tone: 'is-muted' },
    default: { label: 'Built-in default', tone: 'is-muted' },
};

/**
 * One level's wording — the organisation's, or one branch's.
 *
 * Starts from that level's own text when it has one, otherwise from what is
 * sent today, so editing never starts from a blank. The variable chips insert
 * at the cursor; anything else in braces is refused by the server by name,
 * and the message is shown under the field it is about.
 */
function LevelEditor({
    row,
    level,
    branchId,
    heading,
    resetLabel,
}: {
    row: NotificationTypeRow;
    level: TemplateLevel | null;
    branchId: number | null;
    heading: string;
    resetLabel: string;
}) {
    const save = useSaveNotificationTemplate();
    const reset = useResetNotificationTemplate();

    const [title, setTitle] = useState(level?.title ?? row.effective.title);
    const [body, setBody] = useState(level?.body ?? row.effective.body);
    const [active, setActive] = useState(level?.is_active ?? true);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const bodyRef = useRef<HTMLTextAreaElement>(null);

    useEffect(() => {
        setTitle(level?.title ?? row.effective.title);
        setBody(level?.body ?? row.effective.body);
        setActive(level?.is_active ?? true);
        setErrors({});
    }, [level, row.effective.title, row.effective.body]);

    function insert(name: string) {
        const token = `{{${name}}}`;
        const field = bodyRef.current;

        if (!field) {
            setBody((current) => current + token);

            return;
        }

        const start = field.selectionStart ?? body.length;
        const end = field.selectionEnd ?? body.length;
        const next = body.slice(0, start) + token + body.slice(end);

        setBody(next);

        requestAnimationFrame(() => {
            field.focus();
            field.setSelectionRange(start + token.length, start + token.length);
        });
    }

    async function onSave() {
        setErrors({});

        try {
            await save.mutateAsync({ type: row.key, branchId, title, body, is_active: active });
        } catch (error) {
            const found = getValidationErrors(error);

            if (found) {
                setErrors(found);
            } else {
                notify.error(resolveErrorMessage(error));
            }
        }
    }

    async function onReset() {
        try {
            await reset.mutateAsync({ type: row.key, branchId });
        } catch (error) {
            notify.error(resolveErrorMessage(error));
        }
    }

    return (
        <div className="nt-level">
            <div className="nt-level-head">
                <b>{heading}</b>
                {level ? (
                    <span className={`comm-pill ${level.is_active ? 'is-live' : 'is-muted'}`}>
                        {level.is_active ? 'Own wording' : 'Own wording, switched off'}
                    </span>
                ) : (
                    <span className="comm-pill is-muted">Inherited</span>
                )}
            </div>

            <label className="form-label" htmlFor={`nt-title-${row.key}-${branchId ?? 'org'}`}>
                Title
            </label>
            <input
                id={`nt-title-${row.key}-${branchId ?? 'org'}`}
                className={`form-control${errors.title ? ' is-invalid' : ''}`}
                maxLength={120}
                value={title}
                onChange={(event) => setTitle(event.target.value)}
            />
            {errors.title && <div className="invalid-feedback d-block">{errors.title[0]}</div>}

            <label className="form-label mt-2" htmlFor={`nt-body-${row.key}-${branchId ?? 'org'}`}>
                Message
            </label>
            <textarea
                id={`nt-body-${row.key}-${branchId ?? 'org'}`}
                ref={bodyRef}
                className={`form-control${errors.body ? ' is-invalid' : ''}`}
                rows={3}
                maxLength={500}
                value={body}
                onChange={(event) => setBody(event.target.value)}
            />
            {errors.body && <div className="invalid-feedback d-block">{errors.body[0]}</div>}

            <div className="nt-chips" aria-label="Insert a variable">
                {row.variables.map((variable) => (
                    <button
                        type="button"
                        key={variable.name}
                        className="comm-tag nt-chip"
                        title={`Insert ${variable.label}`}
                        onClick={() => insert(variable.name)}
                    >
                        {`{{${variable.name}}}`}
                    </button>
                ))}
            </div>

            <div className="nt-actions">
                <div className="form-check form-switch m-0">
                    <input
                        className="form-check-input"
                        type="checkbox"
                        role="switch"
                        id={`nt-active-${row.key}-${branchId ?? 'org'}`}
                        checked={active}
                        onChange={() => setActive((current) => !current)}
                    />
                    <label className="form-check-label" htmlFor={`nt-active-${row.key}-${branchId ?? 'org'}`}>
                        Use this wording
                    </label>
                </div>

                <div className="d-flex gap-2">
                    {level && (
                        <Button variant="light" size="sm" loading={reset.isPending} onClick={() => void onReset()}>
                            {resetLabel}
                        </Button>
                    )}
                    <Button size="sm" icon="ti ti-device-floppy" loading={save.isPending} onClick={() => void onSave()}>
                        Save
                    </Button>
                </div>
            </div>
        </div>
    );
}

/**
 * What the patient app says — push notifications and the in-app bell —
 * worded at three levels: the platform's default, the organisation's, and
 * each branch's. The most specific active one wins.
 *
 * Email and WhatsApp keep their own templates on their own screens: WhatsApp
 * wording has to be approved by the provider, so it cannot be reworded per
 * branch.
 */
export default function NotificationTemplatesPage() {
    const { can, branches, user } = useTenantAuth();
    const isOwner = user?.role === 'owner';
    const manageOrganization = can('communication.manage');

    // The owner and head office pick from every branch; staff from their own.
    const { data: locations } = locationsHooks.useList({ per_page: 100 });
    const options = useMemo(
        () =>
            branches.length > 0
                ? branches.map((branch) => ({ id: branch.id, name: branch.name ?? `Branch ${branch.id}` }))
                : (locations ?? []).filter((location) => location.is_active).map((location) => ({ id: location.id, name: location.name })),
        [branches, locations],
    );

    const [branchId, setBranchId] = useState<number | null>(null);
    const seesOrganization = isOwner || manageOrganization;

    // Somebody with no organisation-wide view opens on their own branch.
    useEffect(() => {
        if (!seesOrganization && branchId === null && options.length > 0) {
            setBranchId(options[0].id);
        }
    }, [seesOrganization, branchId, options]);

    const { data, isLoading } = useNotificationTemplates(branchId);

    const editBranch = branchId !== null && (manageOrganization || can('communication.branch_templates'));

    return (
        <>
            <PageHeader
                title="App notifications"
                icon="ti ti-bell-ringing"
                tone="sky"
                crumbs={[{ label: 'Communication' }, { label: 'App notifications' }]}
                subtitle="What patients are told on their phone and in the app. A branch's own wording beats the organisation's, which beats the platform default."
            />

            <Card className="mb-3">
                <div style={{ maxWidth: 320 }}>
                    <label className="form-label" htmlFor="nt-branch">
                        Showing
                    </label>
                    <select
                        id="nt-branch"
                        className="form-select"
                        value={branchId ?? ''}
                        onChange={(event) => setBranchId(event.target.value === '' ? null : Number(event.target.value))}
                    >
                        {seesOrganization && <option value="">The whole organisation</option>}
                        {options.map((option) => (
                            <option key={option.id} value={option.id}>
                                {option.name}
                            </option>
                        ))}
                    </select>
                </div>
            </Card>

            {isLoading || !data ? (
                <LoadingBlock label="Loading notifications…" />
            ) : (
                <div className="d-flex flex-column gap-3">
                    {data.types.map((row) => (
                        <Card
                            key={row.key}
                            title={row.label}
                            icon="ti ti-bell"
                            description={`Sent to the ${row.audience}.`}
                            actions={
                                <span className={`comm-pill ${SOURCE[row.effective.source].tone}`}>
                                    From: {SOURCE[row.effective.source].label}
                                </span>
                            }
                        >
                            <div className="nt-preview">
                                <small>What is sent{branchId === null ? '' : ' at this branch'}</small>
                                <b>{row.effective.title}</b>
                                <p>{row.effective.body}</p>
                            </div>

                            <div className="nt-levels">
                                {manageOrganization && (
                                    <LevelEditor
                                        row={row}
                                        level={row.organization}
                                        branchId={null}
                                        heading="Organisation"
                                        resetLabel="Use the platform default"
                                    />
                                )}

                                {editBranch && (
                                    <LevelEditor
                                        row={row}
                                        level={row.branch}
                                        branchId={branchId}
                                        heading={options.find((option) => option.id === branchId)?.name ?? 'This branch'}
                                        resetLabel="Use the organisation's"
                                    />
                                )}
                            </div>
                        </Card>
                    ))}
                </div>
            )}
        </>
    );
}
