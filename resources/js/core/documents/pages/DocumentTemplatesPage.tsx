import { useEffect, useMemo, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
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
import { EDITOR_TABS, TemplateEditor, type EditorTab } from '../components/TemplateEditor';
import { TemplateLocks } from '../components/TemplateLocks';

export default function DocumentTemplatesPage() {
    const { can, activeBranch } = useTenantAuth();
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

    const [previewUrl, setPreviewUrl] = useState<string | null>(null);
    const [previewing, setPreviewing] = useState(false);
    const lastUrl = useRef<string | null>(null);

    useEffect(
        () => () => {
            if (lastUrl.current) URL.revokeObjectURL(lastUrl.current);
        },
        [],
    );

    async function refreshPreview(config?: TemplateConfig) {
        const source = config ?? draft;
        if (!detail || !source) return;

        setPreviewing(true);
        setError(null);

        try {
            const url = await previewTemplate(
                detail.template.document_type,
                source,
                detail.template.location_id,
            );

            if (lastUrl.current) URL.revokeObjectURL(lastUrl.current);
            lastUrl.current = url;
            setPreviewUrl(url);
        } catch (failure) {
            setError(resolveErrorMessage(failure));
        } finally {
            setPreviewing(false);
        }
    }

    useEffect(() => {
        if (detail && draft && !previewUrl) void refreshPreview();
    }, [detail?.template.id, draft !== null]);

    function choose(id: number) {
        const updated = new URLSearchParams(params);
        updated.set('template', String(id));
        setParams(updated, { replace: true });

        if (lastUrl.current) URL.revokeObjectURL(lastUrl.current);
        lastUrl.current = null;
        setPreviewUrl(null);
    }

    function edit(next: TemplateConfig) {
        setDraft(next);
        setDirty(true);
    }

    async function publishDraft() {
        if (!detail) return;
        setError(null);

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
        void refreshPreview(type.defaults);
        notify.info('Loaded organisation defaults. Press Save Changes to confirm.');
    }

    if (isLoading) return <LoadingBlock label="Loading templates…" />;

    const current = detail?.template;

    return (
        <div className="dt-container">
            {/* Top Header Card */}
            <div className="dt-header-card">
                <div className="dt-header-left">
                    <div className="dt-header-icon">
                        <i className={current?.icon ?? 'ti ti-file-text'} aria-hidden="true" />
                    </div>
                    <div>
                        <h4>{current?.document_type_name ?? 'Document Templates'}</h4>
                        <p>Configure the look and content of your clinic invoice. These settings will be used when generating invoices for patients.</p>
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
                                    disabled={publish.isPending}
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
                                    <i className={`dt-doc-icon ${template.icon}`} aria-hidden="true" />
                                    <div className="dt-doc-info">
                                        <span className="dt-doc-name">{template.document_type_name}</span>
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

                    {detail && canLock && detail.template.is_organization_default && (
                        <div className="mt-3">
                            <TemplateLocks
                                templateId={detail.template.id}
                                current={detail.template.active_version?.locked_fields ?? []}
                            />
                        </div>
                    )}
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

                            <TemplateEditor
                                tab={tab}
                                config={draft}
                                placeholders={detail.placeholders}
                                lockedPaths={detail.locked_fields}
                                disabled={!canEdit}
                                logoHint={
                                    activeBranch
                                        ? {
                                              label: 'Open the branch',
                                              to: `/locations/${activeBranch}/edit`,
                                          }
                                        : { label: 'Open organisation setup', to: '/setup' }
                                }
                                onChange={edit}
                            />
                        </>
                    )}
                </div>

                {/* Column 3: Live Preview Panel */}
                <div className="dt-preview-col">
                    <div className="dt-preview-card">
                        <div className="dt-preview-header">
                            <div>
                                <strong className="d-block text-body fs-13">Live Preview</strong>
                                <span className="small text-muted">Sample data, never a real patient.</span>
                            </div>

                            <div className="d-flex align-items-center gap-2">
                                <select className="form-select form-select-sm" style={{ width: 'auto' }}>
                                    <option value="a4">A4 (PDF)</option>
                                    <option value="receipt">Till roll</option>
                                </select>
                                <button
                                    type="button"
                                    className="btn btn-sm btn-light p-1"
                                    title="Refresh PDF"
                                    onClick={() => void refreshPreview()}
                                >
                                    <i className="ti ti-printer" />
                                </button>
                            </div>
                        </div>

                        {/* A4 Live Sheet Preview */}
                        <div className="dt-preview-sheet">
                            {/* Sheet Header */}
                            <div className="dt-sheet-header">
                                <div className="dt-sheet-brand">
                                    <div className="dt-sheet-logo-title">
                                        {draft?.header.show_logo && (
                                            <div className="dt-sheet-logo">
                                                <svg width="32" height="32" viewBox="0 0 24 24" fill="none">
                                                    <path d="M12 2L2 7L12 12L22 7L12 2Z" fill="#0284c7" />
                                                    <path d="M2 17L12 22L22 17" stroke="#0284c7" strokeWidth="2" />
                                                    <path d="M2 12L12 17L22 12" stroke="#0284c7" strokeWidth="2" />
                                                </svg>
                                            </div>
                                        )}
                                        <div>
                                            <div className="dt-sheet-clinic-name">
                                                {draft?.header.legal_name || 'ABC Healthcare'}
                                            </div>
                                            <small className="text-muted d-block" style={{ fontSize: '10px' }}>
                                                Care Today, Healthier Tomorrow.
                                            </small>
                                        </div>
                                    </div>
                                    <div style={{ fontSize: '10.5px', color: '#64748b', marginTop: '6px' }}>
                                        {draft?.header.lines?.[0] || '123 MG Road, Sector 28, Gurgaon - 122001, Haryana'}
                                        <br />
                                        {draft?.header.lines?.[1] || 'Ph: +91 124 456 7890 | Email: info@abchealthcare.com'}
                                        {draft?.header.registration_no && (
                                            <>
                                                <br />
                                                GSTIN: {draft.header.registration_no}
                                            </>
                                        )}
                                    </div>
                                </div>

                                <div className="dt-sheet-title-box">
                                    <div className="dt-sheet-doc-title">
                                        {draft?.header.title || 'TAX INVOICE'}
                                    </div>
                                    <div className="dt-sheet-inv-num">INV-000125</div>
                                    <div style={{ fontSize: '9px', color: '#64748b', marginTop: '4px', textAlign: 'center' }}>
                                        <i className="ti ti-qrcode fs-3 d-block" />
                                        Scan to view online
                                    </div>
                                </div>
                            </div>

                            {/* Patient & Visit Grid */}
                            <div className="dt-sheet-details-grid">
                                <div>
                                    <div><strong>Patient Name:</strong> Rahul Sharma</div>
                                    <div><strong>Patient ID:</strong> PT-001245</div>
                                    <div><strong>Age / Gender:</strong> 32 Years / Male</div>
                                    <div><strong>Phone:</strong> +91 98765 43210</div>
                                </div>
                                <div>
                                    <div><strong>Visit No.:</strong> OPD-1024</div>
                                    <div><strong>Date & Time:</strong> 27 Sep 2026, 10:24 AM</div>
                                    <div><strong>Doctor:</strong> Dr. Amit Gupta</div>
                                    <div><strong>Department:</strong> General Medicine</div>
                                </div>
                            </div>

                            {/* Items Table */}
                            <table className="dt-sheet-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Item / Service</th>
                                        <th>HSN/SAC</th>
                                        <th>Qty</th>
                                        <th>Rate (₹)</th>
                                        <th>Tax %</th>
                                        <th>Amount (₹)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>1</td>
                                        <td><strong>Registration Fee</strong></td>
                                        <td>999316</td>
                                        <td>1</td>
                                        <td>100.00</td>
                                        <td>0%</td>
                                        <td>100.00</td>
                                    </tr>
                                    <tr>
                                        <td>2</td>
                                        <td><strong>Consultation Fee</strong></td>
                                        <td>999312</td>
                                        <td>1</td>
                                        <td>500.00</td>
                                        <td>0%</td>
                                        <td>500.00</td>
                                    </tr>
                                    <tr>
                                        <td>3</td>
                                        <td><strong>Injection</strong></td>
                                        <td>999319</td>
                                        <td>1</td>
                                        <td>100.00</td>
                                        <td>0%</td>
                                        <td>100.00</td>
                                    </tr>
                                    <tr>
                                        <td>4</td>
                                        <td><strong>Complete Blood Count (CBC)</strong></td>
                                        <td>998346</td>
                                        <td>1</td>
                                        <td>250.00</td>
                                        <td>0%</td>
                                        <td>250.00</td>
                                    </tr>
                                    <tr>
                                        <td>5</td>
                                        <td><strong>Emeset 4 mg (Tablet)</strong></td>
                                        <td>300490</td>
                                        <td>20</td>
                                        <td>8.50</td>
                                        <td>5%</td>
                                        <td>170.00</td>
                                    </tr>
                                </tbody>
                            </table>

                            {/* Summary Totals */}
                            <div className="dt-sheet-totals">
                                <div className="dt-sheet-totals-box">
                                    <div className="dt-sheet-totals-row">
                                        <span>Subtotal</span>
                                        <span>₹ 1,516.00</span>
                                    </div>
                                    <div className="dt-sheet-totals-row">
                                        <span>Discount (-)</span>
                                        <span>₹ 100.00</span>
                                    </div>
                                    <div className="dt-sheet-totals-row">
                                        <span>GST (5%)</span>
                                        <span>₹ 75.80</span>
                                    </div>
                                    <div className="dt-sheet-totals-row is-grand">
                                        <span>Total Amount</span>
                                        <span>₹ 1,491.80</span>
                                    </div>
                                </div>
                            </div>

                            {/* Paid Stamp */}
                            <div className="dt-sheet-stamp">
                                <i className="ti ti-circle-check-filled" />
                                <span>PAID · Thank you for choosing {draft?.header.legal_name || 'ABC Healthcare'}</span>
                            </div>

                            {/* Footer & Signature */}
                            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end', paddingTop: '10px' }}>
                                <div style={{ fontSize: '10px', color: '#94a3b8' }}>
                                    DOC-00117 • Printed 27 Sep 2026, 10:26 AM
                                </div>
                                <div style={{ textAlign: 'right' }}>
                                    <div style={{ borderBottom: '1px solid #0f172a', width: '120px', marginBottom: '4px' }}></div>
                                    <small className="fw-600">Authorised Signature</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
