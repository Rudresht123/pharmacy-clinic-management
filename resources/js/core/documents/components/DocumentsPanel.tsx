import { useRef, useState } from 'react';
import { Button } from '@/shared/components/ui/Button';
import { LoadingBlock } from '@/shared/components/ui/Feedback';
import { resolveErrorMessage } from '@/shared/api/http';
import { formatDate } from '@/shared/utils/format';
import { notify } from '@/shared/utils/notify';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { ReasonDialog } from '@/core/medicines/components/ReasonDialog';
import {
    fileSize,
    openDocument,
    useDocumentCategories,
    usePatientDocuments,
    useRemoveDocument,
    useUploadDocument,
    type PatientDocument,
} from '../api';

/**
 * A patient's documents — the real ones.
 *
 * What somebody sees here is already narrowed by the server: a reader without
 * `documents.view_clinical` is not sent the lab reports at all, so this list
 * does no filtering of its own and cannot disagree with the API about who may
 * see what. The two write actions ARE gated here, because a button that can
 * only answer 403 is worse than no button.
 *
 * `appointmentId` attaches an upload to the visit as well as to the person,
 * which is what makes "the reports from today" answerable later.
 */
export function DocumentsPanel({
    customerId,
    appointmentId,
}: {
    customerId: number;
    appointmentId?: number | null;
}) {
    const { can } = useTenantAuth();

    const mayUpload = can('documents.upload');
    const mayRemove = can('documents.delete');

    const { data: documents, isLoading } = usePatientDocuments(customerId);
    const { data: categories } = useDocumentCategories();

    const upload = useUploadDocument();
    const remove = useRemoveDocument(customerId);

    const picker = useRef<HTMLInputElement>(null);

    const [category, setCategory] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [removing, setRemoving] = useState<PatientDocument | null>(null);
    const [busy, setBusy] = useState<number | null>(null);

    /*
     * The category is chosen BEFORE the file, not after.
     *
     * What a document is decides who may read it, so asking afterwards would
     * mean a window in which a lab report is filed under whatever the form
     * happened to default to. An unset category simply does not open the
     * picker.
     */
    function choose() {
        if (!category) {
            setError('Choose what kind of document this is first.');

            return;
        }

        setError(null);
        picker.current?.click();
    }

    async function send(file: File) {
        setError(null);

        try {
            await upload.mutateAsync({ customerId, file, category, appointmentId });
            setCategory('');
        } catch (failure) {
            setError(resolveErrorMessage(failure));
        }
    }

    /** The bytes come through an authorised request, never an href. */
    async function open(document: PatientDocument, download: boolean) {
        setBusy(document.id);

        try {
            await openDocument(document, download);
        } catch (failure) {
            notify.error(resolveErrorMessage(failure));
        } finally {
            setBusy(null);
        }
    }

    if (isLoading) {
        return <LoadingBlock label="Reading the folder…" />;
    }

    const rows = documents ?? [];

    return (
        <div className="cn-body">
            {mayUpload && (
                <div className="doc-add">
                    <select
                        className="form-select"
                        value={category}
                        aria-label="Kind of document"
                        onChange={(event) => {
                            setCategory(event.target.value);
                            setError(null);
                        }}
                    >
                        <option value="">Kind of document…</option>

                        {(categories ?? []).map((item) => (
                            <option key={item.key} value={item.key}>
                                {item.name}
                            </option>
                        ))}
                    </select>

                    <Button
                        icon="ti ti-cloud-upload"
                        onClick={choose}
                        loading={upload.isPending}
                        variant="light"
                    >
                        Attach a file
                    </Button>

                    <input
                        ref={picker}
                        type="file"
                        className="d-none"
                        accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.heif,.tif,.tiff,.doc,.docx"
                        onChange={(event) => {
                            const file = event.target.files?.[0];

                            // Cleared straight away, so picking the same file
                            // twice in a row still fires a change.
                            event.target.value = '';

                            if (file) {
                                void send(file);
                            }
                        }}
                    />
                </div>
            )}

            {error && <p className="alert alert-danger py-2 px-3">{error}</p>}

            {rows.length === 0 ? (
                <p className="doc-empty">
                    <i className="ti ti-folder-open" aria-hidden="true" />
                    {mayUpload
                        ? 'Nothing attached yet. Pick a kind and add the first file.'
                        : 'Nothing attached to this patient.'}
                </p>
            ) : (
                <ul className="cn-docs">
                    {rows.map((document) => (
                        <li key={document.id}>
                            <i
                                className={`cn-doc-kind is-${document.tone} ${document.icon}`}
                                aria-hidden="true"
                            />

                            <span className="cn-doc-what">
                                <b>{document.title}</b>
                                <small>
                                    {fileSize(document.file_size)} ·{' '}
                                    {formatDate(document.created_at)}
                                    {document.uploaded_by_name
                                        ? ` · ${document.uploaded_by_name}`
                                        : ''}
                                </small>
                            </span>

                            <em className="cn-doc-tag">{document.category_name}</em>

                            <span className="cn-doc-acts">
                                <button
                                    type="button"
                                    disabled={busy === document.id}
                                    aria-label={`Open ${document.title}`}
                                    title="Open"
                                    onClick={() => void open(document, false)}
                                >
                                    <i className="ti ti-eye" aria-hidden="true" />
                                </button>

                                <button
                                    type="button"
                                    disabled={busy === document.id}
                                    aria-label={`Download ${document.title}`}
                                    title="Download"
                                    onClick={() => void open(document, true)}
                                >
                                    <i className="ti ti-download" aria-hidden="true" />
                                </button>

                                {mayRemove && (
                                    <button
                                        type="button"
                                        aria-label={`Remove ${document.title}`}
                                        title="Remove"
                                        onClick={() => setRemoving(document)}
                                    >
                                        <i className="ti ti-trash" aria-hidden="true" />
                                    </button>
                                )}
                            </span>
                        </li>
                    ))}
                </ul>
            )}

            {/*
                A reason, like every other removal in the system. A medical
                document taken off a record has to stay answerable for: the row
                is kept, hidden, with who removed it and why.
            */}
            <ReasonDialog
                open={removing !== null}
                title={`Remove ${removing?.title ?? 'document'}?`}
                subtitle="It stops appearing on this patient's record. Nothing is erased."
                submitLabel="Remove document"
                danger
                submitting={remove.isPending}
                error={remove.isError ? resolveErrorMessage(remove.error) : null}
                onClose={() => {
                    setRemoving(null);
                    remove.reset();
                }}
                onSubmit={(reason) => {
                    if (!removing) {
                        return;
                    }

                    remove.mutate(
                        { id: removing.id, reason },
                        { onSuccess: () => setRemoving(null) },
                    );
                }}
            />
        </div>
    );
}
