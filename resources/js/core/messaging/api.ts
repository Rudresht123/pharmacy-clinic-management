import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { http } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import type { ApiResponse } from '@/shared/types/api';
import type { MessagingChannel, ProviderSettings } from './types';

const ROOT = '/admin/messaging';

/**
 * A channel's sending account, platform-wide.
 *
 * Never cached beyond the screen: the answer says whether a token is present,
 * and a stale "yes" would show a green tick over credentials since replaced.
 */
export function useMessagingSettings(channel: MessagingChannel) {
    return useQuery({
        queryKey: ['messaging', channel],
        staleTime: 0,
        queryFn: async () => {
            const { data } = await http.get<ApiResponse<ProviderSettings>>(`${ROOT}/${channel}`);

            return data.data;
        },
    });
}

/** A blank secret means "leave it alone" — the server keeps the stored one. */
export function useSaveMessagingSettings(channel: MessagingChannel) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (body: { provider_key: string; credentials: Record<string, string> }) => {
            const { data } = await http.put<ApiResponse<unknown>>(`${ROOT}/${channel}`, body);

            return data;
        },
        onSuccess: (data) => {
            notify.success(data.message ?? 'Settings saved');
            void queryClient.invalidateQueries({ queryKey: ['messaging', channel] });
        },
    });
}

/**
 * Ask the provider whether the credentials work, without messaging anybody.
 *
 * Deliberately not a toast on its own — "did it work" is the whole question
 * being asked, and a message that disappears is the wrong place for the answer.
 */
export function useTestMessaging(channel: MessagingChannel) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async () => {
            const { data } = await http.post<ApiResponse<{ success: boolean }>>(
                `${ROOT}/${channel}/test`,
                {},
            );

            return data;
        },
        onSettled: () => {
            void queryClient.invalidateQueries({ queryKey: ['messaging', channel] });
        },
    });
}
