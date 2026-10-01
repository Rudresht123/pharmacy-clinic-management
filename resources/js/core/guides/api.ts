import { useQuery } from '@tanstack/react-query';
import { http } from '@/shared/api/http';
import type { ApiResponse } from '@/shared/types/api';

/*
|--------------------------------------------------------------------------
| The Guide section
|--------------------------------------------------------------------------
|
| How the software works, read from `docs/guides` on the server. The folder
| is the list: a guide added there — an .html page and the PDF built from
| it — appears here with no change to this file.
*/

export interface GuideSummary {
    slug: string;
    title: string;
    description: string;
    has_pdf: boolean;
    updated_at: string;
}

export interface Guide extends GuideSummary {
    /** The guide's own page, shown in a sandboxed frame — never as markup here. */
    html: string;
}

const key = (...parts: unknown[]) => ['tenant', 'guides', ...parts];

export function useGuides() {
    return useQuery({
        queryKey: key('list'),
        staleTime: 10 * 60 * 1000,
        queryFn: async (): Promise<GuideSummary[]> => {
            const { data } = await http.get<ApiResponse<GuideSummary[]>>('/tenant/guides');

            return data.data ?? [];
        },
    });
}

export function useGuide(slug: string | undefined) {
    return useQuery({
        queryKey: key('guide', slug),
        enabled: slug !== undefined,
        staleTime: 10 * 60 * 1000,
        queryFn: async (): Promise<Guide> => {
            const { data } = await http.get<ApiResponse<Guide>>(`/tenant/guides/${slug}`);

            return data.data;
        },
    });
}

/** Saves the guide's PDF, fetched with the session like every other file. */
export async function downloadGuidePdf(guide: GuideSummary): Promise<void> {
    const { data } = await http.get<Blob>(`/tenant/guides/${guide.slug}/pdf`, { responseType: 'blob' });

    const url = URL.createObjectURL(data);
    const link = document.createElement('a');

    link.href = url;
    link.download = `${guide.title}.pdf`;
    link.click();

    window.setTimeout(() => URL.revokeObjectURL(url), 30_000);
}
