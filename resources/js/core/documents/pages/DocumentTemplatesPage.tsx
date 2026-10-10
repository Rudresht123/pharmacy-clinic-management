import { useEffect, useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { Card } from '@/shared/components/ui/Card';
import { LoadingBlock } from '@/shared/components/ui/Feedback';
import { resolveErrorMessage } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import {
    previewTemplate,
    useDocumentTemplate,
    useDocumentTemplates,
    useDocumentTypes,
    usePublishTemplate,
    useSaveTemplate,
    type TemplateConfig,
} from '../templates';
import { useBranchLogo, useLocation, useOrganizationLogo } from '@/core/locations/api';
import {
    EDITOR_TABS,
    TemplateEditor,
    type EditorTab,
    type LogoControl,
} from '../components/TemplateEditor';
import { LivePdfPreview } from '../components/LivePdfPreview';

export default function DocumentTemplatesPage() {
    const { can, activeBranch, organization, user, refreshSession } = useTenantAuth();
    const canEdit = can('documents.template_edit');
    const canPublish = can('documents.template_publish');
    const canLock = can('documents.template_org');

    const [params, setParams] = useSearchParams();
    const asked = params.get('template');
    const [tab, setTab] = useState<EditorTab>('content');

    const { data: templates, isLoading } = useDocumentTemplates();
    const { data: types } = useDocumentTypes();

    const selectedId = useMemo(() => {
        if (asked && templates?.some((t) => String(t.id) === asked)) return Number(asked);
        return templates?.[0]?.id;
    }, [asked, templates]);

    const { data: detail, isLoading: detailLoading } = useDocumentTemplate(selectedId);

    const save = useSaveTemplate();
    const publish = usePublishTemplate();

    /*
     * WHOSE LOGO THIS SCREEN IS LOOKING AT.
     *
     * The same branch the preview renders with: the template's own, or the
     * one the user is working at. Null is an organisation-level template with
     * no branch in context — there is no branch record to upload to, and the
     * print falls back to the organisation's mark.
     */
    const logoBranchId = detail?.template.location_id ?? activeBranch;
    const { data: branch } = useLocation(logoBranchId);
    const branchLogo = useBranchLogo(logoBranchId);
    const orgLogo = useOrganizationLogo();

    const [draft, setDraft] = useState<TemplateConfig | null>(null);
    const [dirty, setDirty] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (detail) {
            setDraft(detail.config);
            setDirty(false);
            setError(null);
        }
    }, [detail]);

    /* The printer button's spinner; the pane keeps its own. */
    const [previewing, setPreviewing] = useState(false);

    /**
     * The same PDF the pane shows, in a tab of its own — to print, zoom or
     * save it.
     */
    async function openRealPdf() {
        if (!detail || !draft) return;

        setPreviewing(true);
        setError(null);

        try {
            const url = await previewTemplate(
                detail.template.document_type,
                draft,
                detail.template.location_id,
            );

            window.open(url, '_blank', 'noopener');

            // A moment, so the new tab has read it before the handle goes.
            window.setTimeout(() => URL.revokeObjectURL(url), 30_000);
        } catch (failure) {
            setError(resolveErrorMessage(failure));
        } finally {
            setPreviewing(false);
        }
    }

    function choose(id: number) {
        const updated = new URLSearchParams(params);
        updated.set('template', String(id));
        setParams(updated, { replace: true });
    }

    function edit(next: TemplateConfig) {
        setDraft(next);
        setDirty(true);
    }

    async function publishDraft() {
        if (!detail) return;
        setError(null);

        /*
         * Nothing edited, nothing waiting — say so instead of asking the
         * server to publish a draft that does not exist and showing its
         * refusal as an error. Uploading a logo is the common way to get
         * here: it changes what prints, but it is saved on the branch and
         * leaves the template itself untouched.
         */
        if (!dirty && !detail.has_unpublished_changes) {
            notify.info('Nothing to save — this template is already in use as it is.');

            return;
        }

        try {
            if (dirty && draft) {
                await save.mutateAsync({ id: detail.template.id, config: draft });
                setDirty(false);
            }

            await publish.mutateAsync(detail.template.id);
            notify.success('Published. Settings saved & active for printing.');
        } catch (failure) {
            setError(resolveErrorMessage(failure));
        }
    }

    function resetToDefault() {
        const type = types?.find((t) => t.key === detail?.template.document_type);
        if (!type) return;

        setDraft(type.defaults);
        setDirty(true);
        notify.info('Loaded organisation defaults. Press Save Changes to confirm.');
    }

    /**
     * The logo card's state, derived from what will actually print.
     *
     * Mirrors DocumentService::logo(): the branch's own mark when it has one,
     * the organisation's otherwise. Showing anything else here would be a
     * preview of a document nobody is going to get.
     */
    function logoControl(config: TemplateConfig): LogoControl {
        const organizationMark = organization?.has_logo ? organization.logo_url : null;
        const fromOrganization = config.header.logo_source === 'organization';

        /*
         * WHICH RECORD THE UPLOAD WRITES TO.
         *
         * A branch one whenever there is a branch to write to and the
         * template is not explicitly printing the organisation's mark;
         * otherwise the organisation's own row. Without the second case an
         * organisation-level template — which is every template until
         * somebody makes a branch override — would offer an upload button
         * with nowhere to put the file.
         */
        const toBranch = !fromOrganization && logoBranchId !== null;
        const own = toBranch && Boolean(branch?.has_logo);

        const canChange = toBranch ? can('branches.edit') : user?.role === 'owner';

        const done = (message: string) => {
            notify.success(message);
            /* The organisation's mark is part of the session payload. */
            if (!toBranch) void refreshSession();
        };

        const failed = (failure: unknown) => setError(resolveErrorMessage(failure));

        return {
            url: own ? (branch?.logo_url ?? null) : organizationMark,
            own: toBranch ? own : Boolean(organizationMark),
            canChange,
            busy: toBranch
                ? branchLogo.upload.isPending || branchLogo.remove.isPending
                : orgLogo.upload.isPending || orgLogo.remove.isPending,
            onPick: (file) =>
                (toBranch ? branchLogo.upload : orgLogo.upload).mutate(file as never, {
                    onSuccess: () =>
                        done(
                            toBranch
                                ? 'Logo updated. Every document this branch prints uses it.'
                                : 'Logo updated. Every branch without its own mark prints it.',
                        ),
                    onError: failed,
                }),
            onRemove: () =>
                (toBranch ? branchLogo.remove : orgLogo.remove).mutate(undefined as never, {
                    onSuccess: () =>
                        done(
                            toBranch
                                ? 'Logo removed. This branch prints the organisation’s mark.'
                                : 'Logo removed. Documents now print without a mark.',
                        ),
                    onError: failed,
                }),
            note: canChange
                ? toBranch
                    ? null
                    : 'This is the organisation’s mark — every branch without its own prints it.'
                : toBranch
                  ? 'Changing a branch’s logo needs permission to edit branches.'
                  : 'Only the organisation’s owner can change its logo.',
        };
    }

    /*
     * Plain values, so they sit above the hooks that read them rather than
     * behind the loading guard — which has to stay below every hook, or the
     * hook order changes between the loading render and the loaded one.
     */
    const current = detail?.template;

    /** Edited here, or saved earlier and never put into use. */
    const hasPendingChanges = dirty || Boolean(detail?.has_unpublished_changes);


    // Below every hook on purpose — an early return above one would change
    // the hook order between the loading render and the loaded one.
    if (isLoading) return <LoadingBlock label="Loading templates…" />;

    return (
        <div className="dt-container">
            <nav className="dt-crumbs" aria-label="Breadcrumb">
                <Link to="/settings">Settings</Link>
                <i className="ti ti-chevron-right" aria-hidden="true" />
                <span>Documents</span>
                <i className="ti ti-chevron-right" aria-hidden="true" />
                <span className="is-current">{current?.document_type_name ?? 'Template'}</span>
            </nav>

            {/* Top Header Card */}
            <div className="dt-header-card">
                <div className="dt-header-left">
                    <div className="dt-header-icon">
                        <i className={current?.icon ?? 'ti ti-file-text'} aria-hidden="true" />
                    </div>
                    <div>
                        <h4>{current?.document_type_name ?? 'Document Templates'}</h4>
                        <p>
                            Configure the look and content of your{' '}
                            {(current?.document_type_name ?? 'document').toLowerCase()}. These
                            settings are used every time one is printed for a patient.
                        </p>
                    </div>
                </div>

                <div className="dt-header-actions">
                    {detail && canEdit && (
                        <>
                            <button
                                type="button"
                                className="dt-btn-outline"
                                onClick={resetToDefault}
                                disabled={!types}
                            >
                                <i className="ti ti-rotate" aria-hidden="true" />
                                Reset to organisation default
                            </button>
                            {canPublish && (
                                <button
                                    type="button"
                                    className="btn btn-primary btn-sm px-3 fw-600"
                                    onClick={() => void publishDraft()}
                                    disabled={publish.isPending || !hasPendingChanges}
                                    title={
                                        hasPendingChanges
                                            ? 'Save and put into use'
                                            : 'Nothing has changed'
                                    }
                                >
                                    <i className="ti ti-device-floppy me-1" aria-hidden="true" />
                                    {publish.isPending ? 'Saving…' : 'Save Changes'}
                                </button>
                            )}
                        </>
                    )}
                </div>
            </div>

            {error && <div className="alert alert-danger py-2">{error}</div>}

            {/* 3-Column Layout */}
            <div className="dt-layout">
                {/* Column 1: Documents List Sidebar */}
                <div className="dt-sidebar-card">
                    <h5 className="dt-sidebar-title">Documents</h5>
                    <ul className="dt-doc-list">
                        {(templates ?? []).map((template) => (
                            <li key={template.id}>
                                <button
                                    type="button"
                                    className={`dt-doc-item ${template.id === selectedId ? 'is-active' : ''}`}
                                    onClick={() => choose(template.id)}
                                >
                                    <i
                                        className={`dt-doc-icon ${template.icon}`}
                                        aria-hidden="true"
                                    />
                                    <div className="dt-doc-info">
                                        <span className="dt-doc-name">
                                            {template.document_type_name}
                                        </span>
                                        <span className="dt-doc-sub">
                                            {template.is_organization_default
                                                ? 'Organisation default'
                                                : (template.location_name ?? 'This branch')}
                                        </span>
                                    </div>
                                </button>
                            </li>
                        ))}
                    </ul>
                </div>

                {/* Column 2: Template Settings & Accordions */}
                <div className="dt-editor-col">
                    {detailLoading || !detail || !draft ? (
                        <Card>
                            <LoadingBlock label="Loading template…" />
                        </Card>
                    ) : (
                        <>
                            <div className="dt-tabs-group">
                                {EDITOR_TABS.map((entry) => (
                                    <button
                                        key={entry.value}
                                        type="button"
                                        className={`dt-tab-btn ${tab === entry.value ? 'is-active' : ''}`}
                                        onClick={() => setTab(entry.value)}
                                    >
                                        <i className={entry.icon} aria-hidden="true" />
                                        <span>{entry.label}</span>
                                    </button>
                                ))}
                            </div>

                            <div className="dt-sections">
                                <TemplateEditor
                                    tab={tab}
                                    config={draft}
                                    placeholders={detail.placeholders}
                                    lockedPaths={detail.locked_fields}
                                    disabled={!canEdit}
                                    logo={logoControl(draft)}
                                    organizationName={organization?.name ?? null}
                                    locks={
                                        canLock && detail.template.is_organization_default
                                            ? {
                                                  templateId: detail.template.id,
                                                  current:
                                                      detail.template.active_version
                                                          ?.locked_fields ?? [],
                                              }
                                            : null
                                    }
                                    onChange={edit}
                                />
                            </div>
                        </>
                    )}
                </div>

                {/* Column 3: Live Preview Panel */}
                <div className="dt-preview-col">
                    <div className="dt-preview-card">
                        <div className="dt-preview-header">
                            <div>
                                <strong className="d-block text-body fs-13">Live Preview</strong>
                                <span className="small text-muted">
                                    Sample data, never a real patient.
                                </span>
                            </div>

                            <div className="dt-preview-tools">
                                <button
                                    type="button"
                                    className="dt-preview-print"
                                    title={previewing ? 'Rendering…' : 'Open the PDF in a new tab'}
                                    aria-label="Open the PDF in a new tab"
                                    onClick={() => void openRealPdf()}
                                    disabled={previewing}
                                >
                                    <i
                                        className={previewing ? 'ti ti-loader-2' : 'ti ti-printer'}
                                    />
                                </button>
                            </div>
                        </div>

                        <LivePdfPreview
                            documentType={current?.document_type ?? 'clinic_invoice'}
                            config={draft}
                            locationId={current?.location_id}
                            version={[
                                branch?.has_logo ? branch.logo_url : '',
                                organization?.has_logo ? organization.logo_url : '',
                            ].join('|')}
                        />
                    </div>
                </div>
            </div>
        </div>
    );
}
