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

    /* Only the printer button renders a PDF now, so this is just its spinner. */
    const [previewing, setPreviewing] = useState(false);

    /*
     * NO BACKGROUND RENDER LOOP.
     *
     * While the pane could show a PDF, every edit rebuilt one on the server
     * so the frame had something current in it. The pane shows the sheet
     * now, which the browser draws from the draft for free — so a PDF is
     * built only when somebody actually asks for one, on the printer button.
     */

    /**
     * Show what will actually print.
     *
     * Renders the config being edited through the same PDF engine the
     * patient's copy comes from and opens it. The sketch beside the editor
     * is a browser's drawing of the layout; this is the document.
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

    const docKey = current?.document_type ?? 'clinic_invoice';

    const themeConfig = useMemo(() => {
        if (docKey === 'pharmacy_invoice') {
            return {
                themeClass: 'is-pharmacy',
                accentColor: '#15803d',
                icon: 'ti ti-pill',
                defaultTitle: 'PHARMACY INVOICE',
                defaultSub: 'Quality Medicines. Better Health.',
                tableIcon: 'ti ti-pill',
                tableTitle: 'Medicine Details',
                handoverRole: 'Dispensed By',
                handoverName: 'Amit Patel (Pharmacist)',
                footerSlogan: 'Genuine Medicines | Qualified Pharmacists | Better Care',
                footerThanks: `Thank you for choosing ${draft?.header.legal_name || 'MediCare'} Pharmacy.`,
                columns: [
                    '#',
                    'Medicine Name',
                    'Batch No',
                    'Expiry',
                    'Qty',
                    'Unit Price',
                    'Amount (₹)',
                ],
                rows: [
                    ['1', 'Paracetamol 500mg', 'B12345', '12/2027', '10', '₹ 5.00', '₹ 50.00'],
                    ['2', 'Amoxicillin 500mg', 'A45678', '06/2027', '6', '₹ 20.00', '₹ 120.00'],
                    ['3', 'Cough Syrup 100ml', 'C78901', '01/2028', '1', '₹ 80.00', '₹ 80.00'],
                ],
                subTotal: '₹ 250.00',
                discount: '₹ 20.00',
                tax: '₹ 0.00',
                grandTotal: '₹ 230.00',
                paidAmount: '₹ 230.00',
                dueAmount: '₹ 0.00',
                words: 'Rupees Two Hundred Thirty Only',
                method: 'Card',
                ref: '4263 82XX XXXX 1256',
            };
        } else if (docKey === 'consultation_summary' || docKey === 'prescription') {
            return {
                themeClass: 'is-lab',
                accentColor: '#6d28d9',
                icon: 'ti ti-flask',
                defaultTitle: 'LAB TEST INVOICE',
                defaultSub: 'Accurate Results. Trusted Care.',
                tableIcon: 'ti ti-flask',
                tableTitle: 'Test Details',
                handoverRole: 'Collected By',
                handoverName: 'Neha Singh (Lab Technician)',
                footerSlogan: 'Trusted Diagnostics for a Healthier You',
                footerThanks: `Thank you for choosing ${draft?.header.legal_name || 'MediCare'} Laboratory.`,
                columns: ['#', 'Test Name', 'Sample Type', 'Amount (₹)'],
                rows: [
                    ['1', 'Complete Blood Count (CBC)', 'Blood', '300.00'],
                    ['2', 'Liver Function Test (LFT)', 'Blood', '500.00'],
                ],
                subTotal: '₹ 800.00',
                discount: '₹ 0.00',
                tax: '₹ 0.00',
                grandTotal: '₹ 800.00',
                paidAmount: '₹ 800.00',
                dueAmount: '₹ 0.00',
                words: 'Rupees Eight Hundred Only',
                method: 'Cash',
                ref: '—',
            };
        } else {
            return {
                themeClass: 'is-opd',
                accentColor: '#1d4ed8',
                icon: 'ti ti-clipboard-text',
                defaultTitle: 'OPD PAYMENT RECEIPT',
                defaultSub: 'Consultation • Care • Better Health',
                tableIcon: 'ti ti-receipt',
                tableTitle: 'Billing Details',
                handoverRole: 'Received By',
                handoverName: 'Priya Verma (Receptionist)',
                footerSlogan: 'Care Today, Healthier Tomorrow.',
                footerThanks: `Thank you for choosing ${draft?.header.legal_name || 'MediCare MultiSpeciality'} Clinic.`,
                columns: ['#', 'Description', 'Type', 'Amount (₹)'],
                rows: [['1', 'Consultation Fee - Dr. Amit Sharma', 'OPD', '500.00']],
                subTotal: '₹ 500.00',
                discount: '₹ 0.00',
                tax: '₹ 0.00',
                grandTotal: '₹ 500.00',
                paidAmount: '₹ 500.00',
                dueAmount: '₹ 0.00',
                words: 'Rupees Five Hundred Only',
                method: 'UPI',
                ref: 'UPI123456789',
            };
        }
    }, [docKey, draft?.header.legal_name]);

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
                                    title={previewing ? 'Rendering…' : 'Open real PDF'}
                                    aria-label="Open real PDF"
                                    onClick={() => void openRealPdf()}
                                    disabled={previewing}
                                >
                                    <i
                                        className={previewing ? 'ti ti-loader-2' : 'ti ti-printer'}
                                    />
                                </button>
                            </div>
                        </div>

                        {/*
                            The sheet, and only the sheet. There used to be a
                            PDF frame beside it on a toggle; what it showed is
                            what the printer button opens, so the toggle was
                            two answers to one question.
                        */}
                        <div className={`dt-preview-sheet ${themeConfig.themeClass}`}>
                            {/* Sheet Header */}
                            <div className="dt-sheet-header">
                                <div className="dt-sheet-brand-left">
                                    <div className="dt-sheet-logo-mark">
                                        <i className={themeConfig.icon} aria-hidden="true" />
                                    </div>
                                    <div className="dt-sheet-brand-text">
                                        <div className="dt-sheet-clinic-title">
                                            {draft?.header.legal_name ||
                                                'MediCare MultiSpeciality Clinic'}
                                        </div>
                                        <div className="dt-sheet-clinic-sub">
                                            {draft?.header.department || 'Main Department'}
                                        </div>
                                        <div
                                            style={{
                                                fontSize: '10px',
                                                color: '#64748b',
                                                marginTop: '2px',
                                            }}
                                        >
                                            {draft?.header.tagline ||
                                                'Better Care. Healthier Tomorrow.'}
                                        </div>
                                    </div>
                                </div>

                                <div className="dt-sheet-contact-right">
                                    <div>
                                        📍{' '}
                                        {draft?.header.lines?.[0] ||
                                            '123, Sector 45, Gurugram - 122003'}
                                    </div>
                                    <div>📞 {draft?.header.lines?.[1] || '+91 98765 43210'}</div>
                                    <div>✉️ info@medicareclinic.com</div>
                                    <div>🌐 www.medicareclinic.com</div>
                                </div>
                            </div>

                            {/* Title Banner */}
                            <div className="dt-sheet-banner">
                                <div className="dt-sheet-banner-left">
                                    <div className="dt-sheet-banner-icon">
                                        <i className={themeConfig.icon} aria-hidden="true" />
                                    </div>
                                    <div>
                                        <div className="dt-sheet-banner-title">
                                            {draft?.header.title || themeConfig.defaultTitle}
                                        </div>
                                        <div className="dt-sheet-banner-sub">
                                            {draft?.header.subtitle || themeConfig.defaultSub}
                                        </div>
                                    </div>
                                </div>
                                <i
                                    className={`${themeConfig.icon} fs-1 opacity-25`}
                                    aria-hidden="true"
                                />
                            </div>

                            {/* Meta Row + Paid Badge */}
                            <div className="dt-sheet-meta-row">
                                <div className="dt-sheet-meta-grid">
                                    <span>Receipt No :</span>
                                    <strong>REC-2026-00125</strong>
                                    <span>Payment ID :</span>
                                    <strong>PAY-2026-00451</strong>
                                    <span>Invoice No :</span>
                                    <strong>INV-2026-00891</strong>
                                    <span>Date &amp; Time :</span>
                                    <strong>28 Sep 2026, 10:35 AM</strong>
                                </div>

                                <div className="dt-sheet-paid-stamp">
                                    <div className="dt-sheet-paid-text">
                                        <i className="ti ti-circle-check-filled" />
                                        <span>PAID</span>
                                    </div>
                                    <div className="dt-sheet-paid-sub">
                                        Thank you for your payment.
                                    </div>
                                </div>
                            </div>

                            {/* Patient Details Bar & Grid */}
                            <div>
                                <div className="dt-sheet-section-bar">
                                    <i className="ti ti-user" aria-hidden="true" />
                                    <span>Patient Details</span>
                                </div>

                                <div className="dt-sheet-patient-grid">
                                    <div>
                                        <span>Name :</span> <strong>Rahul Kumar</strong>
                                    </div>
                                    <div>
                                        <span>Mobile No :</span> <strong>98765 43210</strong>
                                    </div>
                                    <div>
                                        <span>Patient ID :</span> <strong>PT-000125</strong>
                                    </div>
                                    <div>
                                        <span>Doctor :</span> <strong>Dr. Amit Sharma</strong>
                                    </div>
                                    <div>
                                        <span>Age / Gender :</span> <strong>32 Years / Male</strong>
                                    </div>
                                    <div>
                                        <span>Visit ID :</span> <strong>VIS-2026-01245</strong>
                                    </div>
                                </div>
                            </div>

                            {/* Table Section */}
                            <div>
                                <div className="dt-sheet-section-bar">
                                    <i className={themeConfig.tableIcon} aria-hidden="true" />
                                    <span>{themeConfig.tableTitle}</span>
                                </div>

                                <table className="dt-sheet-item-table">
                                    <thead>
                                        <tr>
                                            {themeConfig.columns.map((col) => (
                                                <th key={col}>{col}</th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {themeConfig.rows.map((row, idx) => (
                                            <tr key={idx}>
                                                {row.map((cell, cIdx) => (
                                                    <td key={cIdx}>{cell}</td>
                                                ))}
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>

                                {/* Totals Summary */}
                                <div className="dt-sheet-totals mt-2">
                                    <div className="dt-sheet-totals-box">
                                        <div className="dt-sheet-totals-row">
                                            <span>Sub Total</span>
                                            <span>{themeConfig.subTotal}</span>
                                        </div>
                                        <div className="dt-sheet-totals-row">
                                            <span>Discount</span>
                                            <span>{themeConfig.discount}</span>
                                        </div>
                                        <div className="dt-sheet-totals-row">
                                            <span>Tax (GST 0%)</span>
                                            <span>{themeConfig.tax}</span>
                                        </div>
                                    </div>
                                </div>

                                <div className="dt-sheet-grand-banner">
                                    <span>Total Amount</span>
                                    <span>{themeConfig.grandTotal}</span>
                                </div>
                            </div>

                            {/* Payment Information */}
                            <div>
                                <div className="dt-sheet-section-bar">
                                    <i className="ti ti-credit-card" aria-hidden="true" />
                                    <span>Payment Information</span>
                                </div>

                                <div className="dt-sheet-pay-flex">
                                    <div className="dt-sheet-pay-details">
                                        <span>Amount Paid :</span>
                                        <strong>{themeConfig.paidAmount}</strong>
                                        <span>Payment Mode :</span>
                                        <strong>{themeConfig.method}</strong>
                                        <span>Transaction Ref. :</span>
                                        <strong>{themeConfig.ref}</strong>
                                        <span>Amount in Words :</span>
                                        <strong>{themeConfig.words}</strong>
                                    </div>

                                    <div className="dt-sheet-qr-box">
                                        <i className="ti ti-qrcode fs-2 text-dark" />
                                        <small>Scan to Verify</small>
                                        <small style={{ fontSize: '8px', color: '#94a3b8' }}>
                                            REC-2026-00125
                                        </small>
                                    </div>
                                </div>
                            </div>

                            {/* Signatures */}
                            <div className="dt-sheet-signatures">
                                <div className="dt-sheet-sig-card">
                                    <div className="dt-sheet-sig-title">
                                        <i className="ti ti-user" />
                                        <span>{themeConfig.handoverRole}</span>
                                    </div>
                                    <strong className="mt-1">{themeConfig.handoverName}</strong>
                                </div>

                                <div className="dt-sheet-sig-card text-end">
                                    <div className="dt-sheet-sig-title justify-content-end">
                                        <i className="ti ti-pencil" />
                                        <span>Authorized Signature</span>
                                    </div>
                                    <div className="dt-sheet-sig-line"></div>
                                    <small className="text-muted">Authorized Signatory</small>
                                </div>
                            </div>

                            {/* Wave Footer */}
                            <div className="dt-sheet-wave-footer">
                                <strong>{themeConfig.footerSlogan}</strong>
                                <small>{themeConfig.footerThanks}</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
