import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { http } from '@/shared/api/http';
import { resourceKey } from '@/shared/hooks/useResource';
import type { ApiResponse } from '@/shared/types/api';

/**
 * The letterhead editor's API.
 *
 * VERSIONS ARE ADDED TO, NOT EDITED. Saving writes to the template's
 * unpublished draft — creating one if the last version is already published —
 * so the version a document was printed from stays exactly as it was. That is
 * why `has_unpublished_changes` exists and why Save and Publish are two
 * separate acts on the screen.
 */

/** One section of the printed page, as TemplateConfig defines it. */
export interface TemplateConfig {
    header: {
        show_logo: boolean;
        logo_source: 'branch' | 'organization' | 'none';
        logo_position: 'left' | 'center' | 'right';
        title: string;
        subtitle: string;
        lines: string[];
        legal_name: string;
        department: string;
        tagline: string;
        registration_no: string;
        show_divider: boolean;
    };
    body: {
        show_patient: boolean;
        show_visit: boolean;
        show_clinical: boolean;
        tables: { token: string; title: string }[];
        intro: string;
        notes: string;
    };
    footer: {
        lines: string[];
        terms: string;
        show_signature: boolean;
        signature_label: string;
        show_page_numbers: boolean;
    };
    layout: {
        paper: string;
        margin_mm: { top: number; right: number; bottom: number; left: number };
        font_size: number;
        font_family: string;
        accent: string;
    };
}

export interface Placeholder {
    token: string;
    label: string;
    sample: string;
    group: string;
}

export interface DocumentType {
    key: string;
    name: string;
    description: string;
    icon: string;
    subject: string;
    category: string;
    paper: string;
    requires: string[];
    groups: string[];
    placeholders: Placeholder[];
    defaults: TemplateConfig;
}

export interface DocumentTemplate {
    id: number;
    document_type: string;
    document_type_name: string;
    icon: string;
    name: string;
    description: string | null;
    status: string;
    location_id: number | null;
    location_name?: string | null;
    is_organization_default: boolean;
    is_usable: boolean;
    active_version?: {
        id: number;
        version: number;
        published_at: string | null;
        locked_fields: string[];
    } | null;
    versions?: {
        id: number;
        version: number;
        is_published: boolean;
        is_active: boolean;
        published_at: string | null;
        published_by_name: string | null;
        created_at: string | null;
    }[];
}

export interface TemplateDetail {
    template: DocumentTemplate;
    config: TemplateConfig;
    has_unpublished_changes: boolean;
    /** Paths this branch may not change — set by the organisation. */
    locked_fields: string[];
    placeholders: Placeholder[];
}

export interface LockablePath {
    path: string;
    label: string;
}

export function useDocumentTypes() {
    return useQuery({
        queryKey: resourceKey('tenant/documents', 'types'),
        queryFn: async (): Promise<DocumentType[]> => {
            const { data } = await http.get<ApiResponse<DocumentType[]>>('/tenant/document-types');

            return data.data;
        },
    });
}

export function useDocumentTemplates(locationId?: number | null) {
    return useQuery({
        queryKey: resourceKey('tenant/documents', 'templates', locationId ?? 'own'),
        queryFn: async (): Promise<DocumentTemplate[]> => {
            const { data } = await http.get<ApiResponse<DocumentTemplate[]>>(
                '/tenant/document-templates',
                { params: locationId ? { location_id: locationId } : {} },
            );

            return data.data;
        },
    });
}

export function useDocumentTemplate(id: number | undefined) {
    return useQuery({
        queryKey: resourceKey('tenant/documents', 'template', id),
        queryFn: async (): Promise<TemplateDetail> => {
            const { data } = await http.get<ApiResponse<TemplateDetail>>(
                `/tenant/document-templates/${id}`,
            );

            return data.data;
        },
        enabled: id !== undefined,
    });
}

export function useLockablePaths() {
    return useQuery({
        queryKey: resourceKey('tenant/documents', 'lockable'),
        queryFn: async (): Promise<LockablePath[]> => {
            const { data } = await http.get<ApiResponse<LockablePath[]>>(
                '/tenant/document-templates/lockable',
            );

            return data.data;
        },
    });
}

/** Save to the draft version — never to what is live. */
export function useSaveTemplate() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async ({
            id,
            config,
            name,
        }: {
            id: number;
            config: TemplateConfig;
            name?: string;
        }): Promise<void> => {
            await http.put(
                `/tenant/document-templates/${id}`,
                { config, ...(name ? { name } : {}) },
                { silent: true },
            );
        },
        onSuccess: (_result, variables) => {
            queryClient.invalidateQueries({
                queryKey: resourceKey('tenant/documents', 'template', variables.id),
            });
            queryClient.invalidateQueries({ queryKey: resourceKey('tenant/documents', 'templates') });
        },
    });
}

/** Put the draft into use. Everything printed after this uses it. */
export function usePublishTemplate() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (id: number): Promise<void> => {
            await http.post(`/tenant/document-templates/${id}/publish`, {}, { silent: true });
        },
        onSuccess: (_result, id) => {
            queryClient.invalidateQueries({
                queryKey: resourceKey('tenant/documents', 'template', id),
            });
            queryClient.invalidateQueries({ queryKey: resourceKey('tenant/documents', 'templates') });
        },
    });
}

/** Create a branch's own template, starting from the organisation's default. */
export function useCreateTemplate() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (payload: {
            document_type: string;
            location_id: number | null;
            name: string;
            config: TemplateConfig;
        }): Promise<DocumentTemplate> => {
            const { data } = await http.post<ApiResponse<DocumentTemplate>>(
                '/tenant/document-templates',
                payload,
                { silent: true },
            );

            return data.data;
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: resourceKey('tenant/documents', 'templates') });
        },
    });
}

/** Drop a branch override, falling back to the organisation default. */
export function useDeleteTemplate() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (id: number): Promise<void> => {
            await http.delete(`/tenant/document-templates/${id}`, { silent: true });
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: resourceKey('tenant/documents', 'templates') });
        },
    });
}

/** Lock paths on the organisation default so branches cannot change them. */
export function useSaveLocks() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async ({ id, paths }: { id: number; paths: string[] }): Promise<void> => {
            await http.put(
                `/tenant/document-templates/${id}/locks`,
                { locked_fields: paths },
                { silent: true },
            );
        },
        onSuccess: (_result, variables) => {
            queryClient.invalidateQueries({
                queryKey: resourceKey('tenant/documents', 'template', variables.id),
            });
        },
    });
}

/**
 * Render the config being edited, as a PDF, with FAKE data.
 *
 * Never a real patient: a preview of a medical document carrying somebody's
 * actual name is the one thing it must not be. Returns an object URL the
 * caller is responsible for revoking.
 */
export async function previewTemplate(
    documentType: string,
    config: TemplateConfig,
    locationId?: number | null,
): Promise<string> {
    const { data } = await http.post<Blob>(
        '/tenant/document-templates/preview',
        { document_type: documentType, config, location_id: locationId ?? null },
        { responseType: 'blob', silent: true },
    );

    return URL.createObjectURL(data);
}
