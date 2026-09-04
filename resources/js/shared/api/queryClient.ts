import { MutationCache, QueryClient } from '@tanstack/react-query';
import axios from 'axios';

/**
 * Every successful write invalidates every read.
 *
 * This is deliberately blunt, and it replaces three separate bugs of the
 * same shape: adding a branch left the branch dropdown on another screen
 * stale, assigning a module left its counts stale, and changing a record
 * left its history stale. Each was "fixed" by naming the affected keys in
 * that one feature — which only works until the next feature forgets, and
 * every new query was another chance to forget.
 *
 * Naming what a write affects is a promise the codebase cannot keep. A
 * customer's branch lives inside the customer *field schema*; an
 * organization's module count is computed on a different endpoint entirely;
 * an audit row can be written by any model in the application. There is no
 * reliable way for the author of a mutation to know every screen that reads
 * what it touched.
 *
 * So nothing is named. React Query refetches only the queries currently on
 * screen and marks the rest stale for whenever they are next mounted, which
 * is a handful of small requests on a screen the user is already waiting on.
 * That is the right trade against a user hard-refreshing the browser to
 * believe what they are looking at.
 *
 * Failures do not invalidate: nothing changed, so nothing is stale.
 */
const mutationCache = new MutationCache({
    onSuccess: () => {
        queryClient.invalidateQueries();
    },
});

export const queryClient = new QueryClient({
    mutationCache,
    defaultOptions: {
        queries: {
            /*
             * Only governs reads that nothing has changed — a write marks
             * everything stale regardless, so this never holds a stale value
             * after an edit.
             */
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
