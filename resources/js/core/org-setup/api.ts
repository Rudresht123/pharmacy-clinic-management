import { useMutation, useQuery } from '@tanstack/react-query';
import { http } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import { resourceKey } from '@/shared/hooks/useResource';
import type { ApiResponse } from '@/shared/types/api';
import type { SetupOrganization, SetupStatus, SetupStepKey } from './types';

const ENDPOINT = 'tenant/setup';

/**
 * Where the organization's setup stands.
 *
 * Refetched after every mutation anywhere (the query client's rule), so
 * adding a branch from its own section moves the progress bar without this
 * screen having to know that it should.
 */
export function useSetupStatus(enabled = true) {
    return useQuery({
        queryKey: resourceKey(ENDPOINT, 'status'),
        queryFn: async (): Promise<SetupStatus> => {
            const { data } = await http.get<ApiResponse<SetupStatus>>('/tenant/setup');

            return data.data;
        },
        enabled,
    });
}

export function useSaveSetupOrganization() {
    return useMutation({
        mutationFn: async (payload: Partial<SetupOrganization> & Record<string, unknown>) => {
            const { data } = await http.put<ApiResponse<SetupStatus>>('/tenant/setup/organization', payload);

            return data.data;
        },
        onSuccess: () => notify.success('Organisation details saved'),
    });
}

/**
 * Sign off a section whose data has saved. Silent: a refusal ("add a
 * department first") is shown in the section that asked.
 */
export function useConfirmSetupStep() {
    return useMutation({
        mutationFn: async (step: Extract<SetupStepKey, 'departments' | 'roles' | 'settings'>) => {
            const { data } = await http.post<ApiResponse<SetupStatus>>(
                `/tenant/setup/steps/${step}/confirm`,
                {},
                { silent: true },
            );

            return data.data;
        },
    });
}

/** Finish the setup. The server checks every required section again. */
export function useCompleteSetup() {
    return useMutation({
        mutationFn: async () => {
            const { data } = await http.post<ApiResponse<SetupStatus>>('/tenant/setup/complete', {}, {
                silent: true,
            });

            return data.data;
        },
        onSuccess: () => notify.success('Organisation setup completed'),
    });
}
