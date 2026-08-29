import { http } from './http';
import type { ApiResponse, Id } from '@/shared/types/api';

/**
 * Builds a typed CRUD client for one API resource.
 *
 * Adding a module becomes a one-liner instead of five hand-written axios
 * calls:  `export const medicinesApi = createResourceApi<Medicine>('medicines')`
 */
export interface Page<TModel> {
    data: TModel[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        from: number | null;
        to: number | null;
    };
}

export interface WriteOptions {
    /**
     * Called with 0–100 as the request body goes up.
     *
     * Only meaningful for FormData carrying a file; a JSON body is one packet
     * and jumps straight to 100.
     */
    onUploadProgress?: (percent: number) => void;
}

export interface ResourceApi<TModel, TPayload = Record<string, unknown>> {
    endpoint: string;
    list(params?: Record<string, unknown>): Promise<TModel[]>;
    /** One page of results, for server-driven tables. */
    paginate(params?: Record<string, unknown>): Promise<Page<TModel>>;
    get(id: Id): Promise<TModel>;
    create(payload: TPayload | FormData, options?: WriteOptions): Promise<TModel>;
    update(id: Id, payload: TPayload | FormData, options?: WriteOptions): Promise<TModel>;
    remove(id: Id): Promise<void>;
}

/** Translates axios's byte counts into the percentage a person sees. */
function progressConfig(options?: WriteOptions) {
    if (!options?.onUploadProgress) {
        return {};
    }

    return {
        onUploadProgress: (event: { loaded: number; total?: number }) => {
            if (!event.total) {
                return;
            }

            options.onUploadProgress?.(Math.round((event.loaded * 100) / event.total));
        },
    };
}

export function createResourceApi<TModel, TPayload = Record<string, unknown>>(
    endpoint: string,
): ResourceApi<TModel, TPayload> {
    return {
        endpoint,

        async list(params) {
            const { data } = await http.get<ApiResponse<TModel[]>>(`/${endpoint}`, { params });

            return data.data;
        },

        async paginate(params) {
            const { data } = await http.get<Page<TModel>>(`/${endpoint}`, { params });

            return data;
        },

        async get(id) {
            const { data } = await http.get<ApiResponse<TModel>>(`/${endpoint}/${id}`);

            return data.data;
        },

        async create(payload, options) {
            const { data } = await http.post<ApiResponse<TModel>>(
                `/${endpoint}`,
                payload,
                progressConfig(options),
            );

            return data.data;
        },

        async update(id, payload, options) {
            // PHP does not populate $_FILES on PUT, so multipart updates go out
            // as POST with Laravel's _method override.
            if (payload instanceof FormData) {
                payload.append('_method', 'PUT');

                const { data } = await http.post<ApiResponse<TModel>>(
                    `/${endpoint}/${id}`,
                    payload,
                    progressConfig(options),
                );

                return data.data;
            }

            const { data } = await http.put<ApiResponse<TModel>>(
                `/${endpoint}/${id}`,
                payload,
                progressConfig(options),
            );

            return data.data;
        },

        async remove(id) {
            await http.delete(`/${endpoint}/${id}`);
        },
    };
}

/**
 * Turns a plain object into FormData, skipping nullish values and
 * converting booleans to the 1/0 Laravel expects from multipart bodies.
 */
export function toFormData(values: Record<string, unknown>): FormData {
    const form = new FormData();

    Object.entries(values).forEach(([key, value]) => {
        if (value === null || value === undefined || value === '') {
            return;
        }

        if (value instanceof File) {
            form.append(key, value);
        } else if (typeof value === 'boolean') {
            form.append(key, value ? '1' : '0');
        } else {
            form.append(key, String(value));
        }
    });

    return form;
}
