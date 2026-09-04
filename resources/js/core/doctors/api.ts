import { useMutation, useQuery } from '@tanstack/react-query';
import { createResourceApi } from '@/shared/api/resource';
import { createResourceHooks, resourceKey } from '@/shared/hooks/useResource';
import { http } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import type { ApiResponse } from '@/shared/types/api';
import type { ConfigurableField } from '@/core/field-settings/types';
import type { Doctor, DoctorSchedule, DoctorScheduleInput } from './types';

export const doctorsApi = createResourceApi<Doctor>('tenant/doctors');

export const doctorsHooks = createResourceHooks(doctorsApi, {
    singular: 'Doctor',
    plural: 'Doctors',
});

/**
 * The field definitions the form and table render from — the code registry
 * with this organization's own preferences and extra fields applied.
 */
export function useDoctorFields() {
    return useQuery({
        queryKey: resourceKey(doctorsApi.endpoint, 'fields'),
        queryFn: async (): Promise<ConfigurableField[]> => {
            const { data } =
                await http.get<ApiResponse<ConfigurableField[]>>('/tenant/doctors/fields');

            return data.data;
        },
        staleTime: 5 * 60 * 1000,
    });
}

/** One doctor's week. Nested under them, because that is what it belongs to. */
export function useDoctorSchedules(id: number | undefined) {
    return useQuery({
        queryKey: resourceKey(doctorsApi.endpoint, 'schedules', id),
        queryFn: async (): Promise<DoctorSchedule[]> => {
            const { data } = await http.get<ApiResponse<DoctorSchedule[]>>(
                `/tenant/doctors/${id}/schedules`,
            );

            return data.data;
        },
        enabled: id !== undefined && !Number.isNaN(id),
    });
}

/**
 * Saves the whole week at once.
 *
 * Not a row at a time: whether a sitting overlaps depends on every other
 * sitting that doctor has that day, so the server has to see the week to
 * answer — and the screen edits a week anyway.
 */
export function useSaveDoctorSchedules(id: number | undefined) {
    return useMutation({
        mutationFn: async (schedules: DoctorScheduleInput[]) => {
            const { data } = await http.put<ApiResponse<DoctorSchedule[]>>(
                `/tenant/doctors/${id}/schedules`,
                { schedules },
            );

            return data.data;
        },
        // Invalidation is the query client's job, for every mutation.
        onSuccess: () => notify.success('Timings updated'),
    });
}
