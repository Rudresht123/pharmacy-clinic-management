import { QueryClient } from '@tanstack/react-query';
import axios from 'axios';

export const queryClient = new QueryClient({
    defaultOptions: {
        queries: {
            staleTime: 30_000,
            refetchOnWindowFocus: false,
            retry(failureCount, error) {
                // Client errors will not fix themselves on a retry.
                if (axios.isAxiosError(error)) {
                    const status = error.response?.status ?? 0;

                    if (status >= 400 && status < 500) {
                        return false;
                    }
                }

                return failureCount < 2;
            },
        },
        mutations: {
            retry: false,
        },
    },
});
