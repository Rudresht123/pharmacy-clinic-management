import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { http } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import type { ApiResponse } from '@/shared/types/api';

/*
|--------------------------------------------------------------------------
| Types
|--------------------------------------------------------------------------
*/

/**
 * One document on a patient's record.
 *
 * There is NO url. The bytes sit on a private disk and leave through one
 * authorised endpoint, so `download_path` is an API path to call with the
 * session — not a link to put in an href.
 */
export interface PatientDocument {
    id: number;
    customer_id: number;
    appointment_id: number | null;

    title: string;
    notes: string | null;

    category: string;
    category_name: string;
    /** Decided by the category on the server, never by the uploader. */
    is_clinical: boolean;
    icon: string;
    tone: string;

    file_name: string | null;
    mime_type: string | null;
    file_size: number | null;
    extension: string | null;

    download_path: string;

    uploaded_by_name: string | null;
    location_name: string | null;

    created_at: string | null;
    updated_at: string | null;
}

export interface DocumentCategory {
    key: string;
    name: string;
    sensitivity: 'clinical' | 'administrative';
    icon: string;
    tone: string;
}

/** "248 KB" — bytes are not a unit anybody reads. */
export function fileSize(bytes: number | null): string {
    if (bytes === null || bytes <= 0) {
        return '—';
    }

    if (bytes < 1024) {
        return `${bytes} B`;
    }

    const kilobytes = bytes / 1024;

    return kilobytes < 1024 ? `${Math.round(kilobytes)} KB` : `${(kilobytes / 1024).toFixed(1)} MB`;
}

/*
|--------------------------------------------------------------------------
| Reads
|--------------------------------------------------------------------------
*/

const key = (...parts: unknown[]) => ['tenant', 'documents', ...parts];

export function usePatientDocuments(customerId: number | undefined) {
    return useQuery({
        queryKey: key('customer', customerId),
        enabled: customerId !== undefined,
        queryFn: async (): Promise<PatientDocument[]> => {
            const { data } = await http.get<ApiResponse<PatientDocument[]>>(
                `/tenant/customers/${customerId}/documents`,
            );

            return data.data ?? [];
        },
    });
}

/**
 * The categories THIS person may file under.
 *
 * Asked of the server rather than hardcoded: which categories somebody sees
 * depends on whether they hold the clinical capability, and a list baked into
 * the bundle would offer a lab technician's options to a billing clerk.
 */
export function useDocumentCategories() {
    return useQuery({
        queryKey: key('categories'),
        staleTime: 10 * 60 * 1000,
        queryFn: async (): Promise<DocumentCategory[]> => {
            const { data } = await http.get<ApiResponse<DocumentCategory[]>>(
                '/tenant/document-categories',
            );

            return data.data ?? [];
        },
    });
}

/*
|--------------------------------------------------------------------------
| Writes
|--------------------------------------------------------------------------
*/

export interface UploadInput {
    customerId: number;
    file: File;
    category: string;
    /** Attaches it to the visit as well as to the person. */
    appointmentId?: number | null;
    title?: string;
    notes?: string;
}

export function useUploadDocument() {
    const client = useQueryClient();

    return useMutation({
        mutationFn: async (input: UploadInput): Promise<PatientDocument> => {
            const body = new FormData();

            body.append('file', input.file);
            body.append('category', input.category);

            if (input.appointmentId) {
                body.append('appointment_id', String(input.appointmentId));
            }

            if (input.title) {
                body.append('title', input.title);
            }

            if (input.notes) {
                body.append('notes', input.notes);
            }

            const { data } = await http.post<ApiResponse<PatientDocument>>(
                `/tenant/customers/${input.customerId}/documents`,
                body,
                // Silent: the form shows the server's answer in place, beside
                // the file that was refused.
                { silent: true },
            );

            return data.data;
        },
        onSuccess: (document) => {
            void client.invalidateQueries({ queryKey: key('customer', document.customer_id) });
            notify.success('Document attached');
        },
    });
}

export function useRemoveDocument(customerId: number | undefined) {
    const client = useQueryClient();

    return useMutation({
        mutationFn: ({ id, reason }: { id: number; reason: string }) =>
            http.delete(`/tenant/documents/${id}`, { data: { reason }, silent: true }),
        onSuccess: () => {
            void client.invalidateQueries({ queryKey: key('customer', customerId) });
            notify.success('Document removed');
        },
    });
}

/**
 * Fetch the bytes and hand them to the browser.
 *
 * Not an <a href>: the endpoint is authorised, so the file has to be asked for
 * with the session rather than opened as a URL. The object URL is revoked
 * immediately after the click — a blob held open is the tab's memory until the
 * tab closes.
 */
/**
 * Print a document from a template and file it against the patient.
 *
 * The controller decides WHICH record the id belongs to from the document
 * type — so a caller cannot ask for a prescription to be rendered from an
 * invoice, and cannot name a table the endpoint does not serve. Silent: the
 * caller shows failures inline (a missing template, an unreachable branch)
 * rather than as a corner toast.
 */
export function useGenerateDocument() {
    return useMutation({
        mutationFn: async ({
            document_type,
            subject_id,
        }: {
            document_type: string;
            subject_id: number;
        }): Promise<PatientDocument> => {
            const { data } = await http.post<ApiResponse<PatientDocument>>(
                '/tenant/documents/generate',
                { document_type, subject_id },
                { silent: true },
            );

            return data.data;
        },
    });
}

export async function openDocument(document_: PatientDocument, download = false): Promise<void> {
    const { data } = await http.get<Blob>(document_.download_path, { responseType: 'blob' });

    const url = URL.createObjectURL(data);

    if (download) {
        const link = document.createElement('a');

        link.href = url;
        link.download = document_.file_name ?? document_.title;
        link.click();
    } else {
        window.open(url, '_blank', 'noopener');
    }

    // A moment, so the new tab has read it before the handle goes.
    window.setTimeout(() => URL.revokeObjectURL(url), 30_000);
}

/*
|--------------------------------------------------------------------------
| Automatic documents
|--------------------------------------------------------------------------
|
| "On this event, at these branches, make this document." What may be
| automated comes from the server — an event only where its module runs, a
| document only where the event carries its record — so the screen offers
| exactly what the automation can do and nothing it cannot.
*/

export interface AutomatableDocument {
    key: string;
    name: string;
    description: string;
}

export interface AutomatableEvent {
    key: string;
    label: string;
    module: string;
    documents: AutomatableDocument[];
}

export interface DocumentRule {
    id: number;
    event_key: string;
    document_type: string;
    /** Null is every branch. */
    location_id: number | null;
}

export interface DocumentRules {
    events: AutomatableEvent[];
    branches: { id: number; name: string; code: string | null }[];
    rules: DocumentRule[];
}

export function useDocumentRules() {
    return useQuery({
        queryKey: key('rules'),
        queryFn: async (): Promise<DocumentRules> => {
            const { data } = await http.get<ApiResponse<DocumentRules>>('/tenant/document-rules');

            return data.data;
        },
    });
}

/** Saves the whole set for one scope — every branch, or one branch. */
export function useSaveDocumentRules() {
    const client = useQueryClient();

    return useMutation({
        mutationFn: async (input: {
            location_id: number | null;
            rules: { event_key: string; document_type: string }[];
        }): Promise<DocumentRules> => {
            const { data } = await http.put<ApiResponse<DocumentRules>>('/tenant/document-rules', input, {
                silent: true,
            });

            return data.data;
        },
        onSuccess: (saved) => {
            client.setQueryData(key('rules'), saved);
        },
    });
}
