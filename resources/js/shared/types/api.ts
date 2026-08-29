/**
 * Shapes returned by the Laravel v1 API.
 *
 * Every endpoint answers with the same envelope, so these three types
 * describe the entire transport layer.
 */

export interface ApiResponse<T> {
    data: T;
    message?: string;
    meta?: ApiMeta;
}

export interface ApiMeta {
    current_page?: number;
    last_page?: number;
    per_page?: number;
    total?: number;
}

/** Body of a 422 response. */
export interface ApiValidationError {
    message: string;
    errors: Record<string, string[]>;
}

/** Body of any non-422 failure. */
export interface ApiError {
    message: string;
}

/** Every model the API returns carries these. */
export interface Timestamps {
    created_at: string | null;
    updated_at?: string | null;
}

/**
 * How a record is addressed in a URL.
 *
 * Numeric for the small reference tables, a 26-character ULID for anything
 * the Build Spec exposes publicly — §5 keeps the row id off the wire so a
 * URL cannot be walked from one record to the next.
 */
export type Id = number | string;
