import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { http } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import type { ApiResponse } from '@/shared/types/api';
import type {
    AudienceFilter,
    AudiencePreview,
    AudienceSegment,
    Campaign,
    Channel,
    ChannelOverview,
    MessageTemplate,
} from './types';

const ROOT = '/tenant/communication';

/** Everything one channel's screen shows, keyed so a send can refresh it. */
export function overviewKey(channel: Channel, days?: number) {
    return ['communication', 'overview', channel, days ?? 30] as const;
}

/**
 * One request for the whole screen.
 *
 * The setup checklist is derived from the same state the template list and the
 * log are read from, so fetching them separately would let the page tell
 * somebody it is ready before it is.
 */
export function useChannelOverview(channel: Channel, days = 30) {
    return useQuery({
        queryKey: overviewKey(channel, days),
        queryFn: async (): Promise<ChannelOverview> => {
            const { data } = await http.get<ApiResponse<ChannelOverview>>(
                `${ROOT}/${channel}/overview`,
                { params: { days } },
            );

            return data.data;
        },
    });
}

/**
 * Switching a rule on or off.
 *
 * The server refuses a rule switched on with nothing to send, or with a
 * template the provider has not approved — so the message is shown rather
 * than assumed, and the overview is refetched rather than patched, because a
 * rule changing can change the setup checklist too.
 */
export function useToggleRule() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async ({ id, enabled }: { id: number; enabled: boolean }) => {
            const { data } = await http.put<ApiResponse<unknown>>(
                `${ROOT}/automation-rules/${id}`,
                { is_enabled: enabled },
            );

            return data;
        },
        onSuccess: (data) => {
            notify.success(data.message ?? 'Rule saved');
            void queryClient.invalidateQueries({ queryKey: ['communication'] });
        },
    });
}

/** The rule's wording, template and timing — everything but its event. */
export function useSaveRule() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async ({ id, ...body }: Record<string, unknown> & { id: number }) => {
            const { data } = await http.put<ApiResponse<unknown>>(
                `${ROOT}/automation-rules/${id}`,
                body,
            );

            return data;
        },
        onSuccess: () => {
            notify.success('Rule saved');
            void queryClient.invalidateQueries({ queryKey: ['communication'] });
        },
    });
}

/**
 * The account a channel sends from.
 *
 * Refetches everything rather than patching: the setup checklist is derived
 * from exactly these fields, so a connection change moves it too.
 */
export function useSaveConnection(channel: Channel) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (body: Record<string, unknown>) => {
            const { data } = await http.put<ApiResponse<unknown>>(
                `${ROOT}/${channel}/connection`,
                body,
            );

            return data;
        },
        onSuccess: (data) => {
            notify.success(data.message ?? 'Connection saved');
            void queryClient.invalidateQueries({ queryKey: ['communication'] });
        },
    });
}

/**
 * Send a test to one address or several.
 *
 * The server takes a list and writes them in one transaction, so a partial
 * test is not possible — half the phones ringing while the checklist ticks is
 * worse than nothing going at all.
 */
export function useSendTest(channel: Channel) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async ({
            recipients,
            templateId,
        }: {
            recipients: string[];
            /** Null sends a plain "this works" rather than a template. */
            templateId: number | null;
        }) => {
            const { data } = await http.post<ApiResponse<unknown>>(`${ROOT}/${channel}/test`, {
                recipients,
                message_template_id: templateId,
            });

            return data;
        },
        onSuccess: (data) => {
            notify.success(data.message ?? 'Test message queued');
            // A test completes the last setup step, so the checklist moves.
            void queryClient.invalidateQueries({ queryKey: ['communication'] });
        },
    });
}

export function useSaveTemplate(channel: Channel) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (template: Partial<MessageTemplate> & { id?: number }) => {
            const { id, ...body } = template;

            const { data } = id
                ? await http.put<ApiResponse<MessageTemplate>>(`${ROOT}/templates/${id}`, body)
                : await http.post<ApiResponse<MessageTemplate>>(`${ROOT}/templates`, {
                      ...body,
                      channel,
                  });

            return data;
        },
        onSuccess: (data) => {
            notify.success(data.message ?? 'Template saved');
            void queryClient.invalidateQueries({ queryKey: ['communication'] });
        },
    });
}

/**
 * Removing a template.
 *
 * Soft on the server, and refused outright while an automation rule still
 * sends it — the message names the rule, because that is what has to change
 * first.
 */
export function useRemoveTemplate() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ id, reason }: { id: number; reason?: string }) =>
            http.delete(`${ROOT}/templates/${id}`, { data: { reason } }),
        onSuccess: () => {
            notify.success('Template removed');
            void queryClient.invalidateQueries({ queryKey: ['communication'] });
        },
    });
}

/* ------------------------------- Campaigns -------------------------------- */

export function useCampaigns(channel: Channel, status?: string) {
    return useQuery({
        queryKey: ['communication', 'campaigns', channel, status ?? 'all'],
        queryFn: async (): Promise<{ data: Campaign[] }> => {
            const { data } = await http.get<{ data: Campaign[] }>(`${ROOT}/campaigns`, {
                params: { channel, status },
            });

            return data;
        },
    });
}

/** The segments a campaign may choose from, counted as they stand now. */
export function useAudienceSegments(channel: Channel) {
    return useQuery({
        queryKey: ['communication', 'segments', channel],
        queryFn: async (): Promise<AudienceSegment[]> => {
            const { data } = await http.get<ApiResponse<AudienceSegment[]>>(`${ROOT}/segments`, {
                params: { channel },
            });

            return data.data;
        },
    });
}

/**
 * How many a set of filters would reach, before committing to it.
 *
 * A mutation rather than a query: it is asked repeatedly while somebody is
 * building filters, and caching an answer keyed on a half-built filter set
 * would just fill the cache with states nobody returns to.
 */
export function useAudiencePreview() {
    return useMutation({
        mutationFn: async ({
            channel,
            filters,
        }: {
            channel: Channel;
            filters: AudienceFilter[];
        }): Promise<AudiencePreview> => {
            const { data } = await http.post<ApiResponse<AudiencePreview>>(
                `${ROOT}/audience-preview`,
                { channel, filters },
            );

            return data.data;
        },
    });
}

export function useSaveCampaign() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (campaign: Partial<Campaign> & { id?: number }) => {
            const { id, ...body } = campaign;

            const { data } = id
                ? await http.put<ApiResponse<Campaign>>(`${ROOT}/campaigns/${id}`, body)
                : await http.post<ApiResponse<Campaign>>(`${ROOT}/campaigns`, body);

            return data;
        },
        onSuccess: (data) => {
            notify.success(data.message ?? 'Campaign saved');
            void queryClient.invalidateQueries({ queryKey: ['communication', 'campaigns'] });
        },
    });
}

/**
 * Set the time and let it go by itself.
 *
 * The server refuses a time in the past and an audience of nobody, and answers
 * with how many it will reach — which is the confirmation worth showing.
 */
export function useScheduleCampaign() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async ({ id, at }: { id: number; at: string }) => {
            const { data } = await http.post<ApiResponse<Campaign>>(
                `${ROOT}/campaigns/${id}/schedule`,
                { scheduled_at: at },
            );

            return data;
        },
        onSuccess: (data) => {
            notify.success(data.message ?? 'Campaign scheduled');
            void queryClient.invalidateQueries({ queryKey: ['communication', 'campaigns'] });
        },
    });
}

export function useUnscheduleCampaign() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (id: number) => http.post(`${ROOT}/campaigns/${id}/unschedule`),
        onSuccess: () => {
            notify.success('Schedule cancelled');
            void queryClient.invalidateQueries({ queryKey: ['communication', 'campaigns'] });
        },
    });
}

export function useSendCampaign() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (id: number) => {
            const { data } = await http.post<ApiResponse<Campaign>>(`${ROOT}/campaigns/${id}/send`);

            return data;
        },
        onSuccess: (data) => {
            notify.success(data.message ?? 'Campaign sending');
            void queryClient.invalidateQueries({ queryKey: ['communication'] });
        },
    });
}

export function useRemoveCampaign() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ id, reason }: { id: number; reason?: string }) =>
            http.delete(`${ROOT}/campaigns/${id}`, { data: { reason } }),
        onSuccess: () => {
            notify.success('Campaign removed');
            void queryClient.invalidateQueries({ queryKey: ['communication', 'campaigns'] });
        },
    });
}

export function useTestConnection(channel: Channel) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async () => {
            const { data } = await http.post<ApiResponse<{ success: boolean; provider: string }>>(
                `${ROOT}/${channel}/test-connection`,
                {},
            );

            return data;
        },
        onSettled: () => {
            void queryClient.invalidateQueries({ queryKey: ['communication', 'provider'] });
        },
    });
}
