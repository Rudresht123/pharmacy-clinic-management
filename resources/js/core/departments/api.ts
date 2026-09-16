import { useMutation } from '@tanstack/react-query';
import { createResourceApi } from '@/shared/api/resource';
import { createResourceHooks } from '@/shared/hooks/useResource';
import { http } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import type { SelectOption } from '@/shared/components/form/SearchableSelect';
import type { Department, DepartmentPayload } from './types';

/** GET returns the whole tree: departments with their sub-departments nested. */
export const departmentsApi = createResourceApi<Department, DepartmentPayload>('tenant/departments');

export const departmentsHooks = createResourceHooks(departmentsApi, {
    singular: 'Department',
    plural: 'Departments',
});

/**
 * Removing takes a reason, so it cannot use the resource's plain DELETE.
 * Silent: a refusal ("3 doctors are in it") is shown in the dialog that asked.
 */
export function useRemoveDepartment() {
    return useMutation({
        mutationFn: ({ id, reason }: { id: number; reason: string }) =>
            http.delete(`/tenant/departments/${id}`, { data: { reason }, silent: true }),
        onSuccess: () => notify.success('Department removed'),
    });
}

/**
 * Every active department and sub-department as a pick list:
 * "Cardiology", then "Cardiology › Interventional Cardiology".
 *
 * `keep` stays on the list even if it was deactivated since, so a record
 * already in it still shows where it is.
 */
export function departmentOptions(tree: Department[], keep?: number | null): SelectOption[] {
    const options: SelectOption[] = [];

    for (const department of tree) {
        if (department.is_active || department.id === keep) {
            options.push({ value: String(department.id), label: department.name });
        }

        for (const child of department.children ?? []) {
            if ((child.is_active && department.is_active) || child.id === keep) {
                options.push({ value: String(child.id), label: `${department.name} › ${child.name}` });
            }
        }
    }

    return options;
}
