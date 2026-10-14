import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { http } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import type { ApiResponse } from '@/shared/types/api';

const ROOT = '/admin/mobile-app';

/** Where development phones find the server, and whether it reached them. */
export interface MobileServer {
    /** `http://192.168.1.24:8000`, or null when phones find it on their own. */
    address: string | null;
    /** What the app is actually given: the address plus the API's path. */
    api_base: string | null;
    /** When Firebase last took it — null when it never has. */
    published_at: string | null;
    /** Why the last save did not reach Firebase. */
    publish_error: string | null;
    /** This computer's own Wi-Fi/LAN addresses, ready to use. */
    suggestions: string[];
}

export function useMobileServer() {
    return useQuery({
        queryKey: ['mobile-app'],
        staleTime: 0,
        queryFn: async () => {
            const { data } = await http.get<ApiResponse<MobileServer>>(ROOT);

            return data.data;
        },
    });
}

export function useSaveMobileServer() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (body: { address: string }) => {
            const { data } = await http.put<ApiResponse<MobileServer>>(ROOT, body);

            return data;
        },
        onSuccess: (data) => {
            if (data.data?.publish_error) {
                notify.error(data.message ?? 'Saved, but it did not reach the phones.');
            } else {
                notify.success(data.message ?? 'Saved');
            }

            queryClient.setQueryData(['mobile-app'], data.data);
        },
    });
}
