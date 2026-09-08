import { useEffect, useRef, useState, type DragEvent, type ReactNode } from 'react';
import { useController, type Control, type FieldErrors, type FieldValues, type Path, type UseFormRegister } from 'react-hook-form';
import { SearchableSelect } from './SearchableSelect';
import { PreviewableImage } from '@/shared/components/ui/PreviewableImage';
import { formatBytes } from '@/shared/utils/format';
import { cn } from '@/shared/utils/cn';

/** Pull a possibly-nested error message out of react-hook-form's tree. */
function messageFor<T extends FieldValues>(
    errors: FieldErrors<T>,
    name: Path<T>,
): string | undefined {
    const error = name.split('.').reduce<any>((acc, part) => (acc ? acc[part] : undefined), errors);

    return typeof error?.message === 'string' ? error.message : undefined;
}

interface BaseFieldProps<T extends FieldValues> {
    name: Path<T>;
    label: string;
    register: UseFormRegister<T>;
    errors: FieldErrors<T>;
    required?: boolean;
    hint?: string;
    className?: string;
}

function FieldShell({
    label,
    htmlFor,
    required,
    hint,
    error,
    className,
    children,
}: {
    label: string;
    htmlFor: string;
    required?: boolean;
    hint?: string;
    error?: string;
    className?: string;
    children: ReactNode;
}) {
    return (
        <div className={cn('mb-3', className)}>
            <label className="form-label" htmlFor={htmlFor}>
                {label}
                {required && <span className="text-danger ms-1">*</span>}
            </label>

            {children}

            {error ? (
                <div className="invalid-feedback d-block">{error}</div>
            ) : (
                hint && <small className="text-muted d-block mt-1">{hint}</small>
            )}
        </div>
    );
}

export function TextField<T extends FieldValues>({
    name,
    label,
    register,
    errors,
    required,
    hint,
    className,
    type = 'text',
    placeholder,
    autoFocus,
    autoComplete,
}: BaseFieldProps<T> & {
    type?: string;
    placeholder?: string;
    autoFocus?: boolean;
    autoComplete?: string;
}) {
    const error = messageFor(errors, name);

    return (
        <FieldShell
            label={label}
            htmlFor={name}
            required={required}
            hint={hint}
            error={error}
            className={className}
        >
            <input
                id={name}
                type={type}
                placeholder={placeholder}
                autoFocus={autoFocus}
                autoComplete={autoComplete}
                className={cn('form-control', error && 'is-invalid')}
                {...register(name)}
            />
        </FieldShell>
    );
}

export function TextareaField<T extends FieldValues>({
    name,
    label,
    register,
    errors,
    required,
    hint,
    className,
    rows = 4,
    placeholder,
}: BaseFieldProps<T> & { rows?: number; placeholder?: string }) {
    const error = messageFor(errors, name);

    return (
        <FieldShell
            label={label}
            htmlFor={name}
            required={required}
            hint={hint}
            error={error}
            className={className}
        >
            <textarea
                id={name}
                rows={rows}
                placeholder={placeholder}
                className={cn('form-control', error && 'is-invalid')}
                {...register(name)}
            />
        </FieldShell>
    );
}

export interface Option {
    value: string | number;
    label: string;
}

/**
 * One from a list, searchable.
 *
 * Controlled rather than registered: the control is a button and a panel, not
 * a native `<select>`, so there is no element for `register` to bind a value
 * to. That is also why it needs `control` — the same reason MultiSelectField
 * does.
 */
export function SelectField<T extends FieldValues>({
    name,
    label,
    control,
    errors,
    required,
    hint,
    className,
    options,
    placeholder = 'Select…',
    loading = false,
}: Omit<BaseFieldProps<T>, 'register'> & {
    control: Control<T>;
    options: Option[];
    placeholder?: string;
    loading?: boolean;
}) {
    const error = messageFor(errors, name);
    const { field } = useController({ name, control });

    return (
        <FieldShell
            label={label}
            htmlFor={name}
            required={required}
            hint={hint}
            error={error}
            className={className}
        >
            <SearchableSelect
                id={name}
                value={field.value === undefined || field.value === null ? '' : String(field.value)}
                onChange={field.onChange}
                options={options.map((option) => ({
                    value: String(option.value),
                    label: option.label,
                }))}
                placeholder={loading ? 'Loading…' : placeholder}
                disabled={loading}
                invalid={Boolean(error)}
                clearable={!required}
            />
        </FieldShell>
    );
}

/** Turns an accept attribute — ".jpg,.png" — into "JPG, PNG". */
function describeAccept(accept?: string): string {
    if (!accept) {
        return 'Any file';
    }

    return accept
        .split(',')
        .map((entry) => entry.trim().replace(/^\./, '').toUpperCase())
        .filter(Boolean)
        .join(', ');
}

/**
 * A drop zone that says what was chosen.
 *
 * The browser's own file input shows "No file chosen" and then a truncated
 * name, with no size and no way to tell whether the picture is the right one.
 * This shows the thumbnail, the name, the size, and the cap — so an upload
 * that is going to be rejected is obvious before the form is submitted
 * rather than after a round trip.
 *
 * react-hook-form still owns the value: the native input is present and
 * registered, only visually hidden.
 */
export function FileField<T extends FieldValues>({
    name,
    label,
    register,
    errors,
    required,
    hint,
    className,
    accept,
    previewUrl,
    maxSizeMb = 2,
    /** Shown inside the zone before anything is chosen. */
    placeholder = 'Drop an image here, or click to browse',
    /** 0–100 while the form is uploading; null when idle. */
    progress = null,
}: BaseFieldProps<T> & {
    accept?: string;
    previewUrl?: string | null;
    maxSizeMb?: number;
    placeholder?: string;
    progress?: number | null;
}) {
    const error = messageFor(errors, name);

    const registration = register(name);
    const inputRef = useRef<HTMLInputElement | null>(null);

    const [file, setFile] = useState<File | null>(null);
    const [localPreview, setLocalPreview] = useState<string | null>(null);
    const [dragging, setDragging] = useState(false);

    const maxBytes = maxSizeMb * 1024 * 1024;
    const tooLarge = file !== null && file.size > maxBytes;

    const uploading = progress !== null;

    /*
     * At 100% the bytes have left the browser but the server is still
     * working — for an organization that means creating a database and
     * running migrations, which is the slow part. Saying "Uploaded" and then
     * sitting there looks like a hang, so the label changes instead.
     */
    const settling = uploading && progress >= 100;

    // Object URLs are revoked on replacement and on unmount; leaving them
    // alive holds the whole file in memory for as long as the page lives.
    useEffect(() => {
        if (!file || !file.type.startsWith('image/')) {
            setLocalPreview(null);

            return;
        }

        const url = URL.createObjectURL(file);
        setLocalPreview(url);

        return () => URL.revokeObjectURL(url);
    }, [file]);

    function adopt(files: FileList | null) {
        setFile(files?.[0] ?? null);
    }

    function onDrop(event: DragEvent<HTMLDivElement>) {
        event.preventDefault();
        setDragging(false);

        const dropped = event.dataTransfer.files;

        if (!dropped?.length || !inputRef.current) {
            return;
        }

        /*
         * Hand the drop to the real input so react-hook-form sees it exactly
         * as it would a click-to-browse selection. The dispatched event
         * bubbles to React's root listener, which runs this component's own
         * onChange — so `adopt` is not called again here.
         */
        inputRef.current.files = dropped;
        inputRef.current.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function clear() {
        if (!inputRef.current) {
            return;
        }

        // Emptying the input and announcing it is what clears the form value;
        // setting local state alone would leave the old file still submitted.
        inputRef.current.value = '';
        inputRef.current.dispatchEvent(new Event('change', { bubbles: true }));
    }

    // The existing upload, shown until a replacement is picked.
    const showStored = !file && previewUrl;

    return (
        <FieldShell
            label={label}
            htmlFor={name}
            required={required}
            hint={hint}
            error={error}
            className={className}
        >
            <div
                className={cn(
                    'filefield',
                    dragging && 'is-dragging',
                    (error || tooLarge) && 'is-invalid',
                    file && 'has-file',
                    uploading && 'is-uploading',
                )}
                onDragOver={(event) => {
                    event.preventDefault();
                    setDragging(true);
                }}
                onDragLeave={() => setDragging(false)}
                onDrop={onDrop}
            >
                <input
                    id={name}
                    type="file"
                    accept={accept}
                    className="filefield-input"
                    {...registration}
                    ref={(element) => {
                        registration.ref(element);
                        inputRef.current = element;
                    }}
                    onChange={(event) => {
                        registration.onChange(event);
                        adopt(event.target.files);
                    }}
                />

                {file ? (
                    <div className="filefield-chosen">
                        {localPreview ? (
                            <img src={localPreview} alt="" className="filefield-thumb" />
                        ) : (
                            <span className="filefield-thumb filefield-thumb--icon">
                                <i className="ti ti-file" />
                            </span>
                        )}

                        <span className="filefield-meta">
                            <b title={file.name}>{file.name}</b>

                            {uploading ? (
                                <small className="filefield-status">
                                    {settling ? (
                                        <>
                                            <span className="filefield-spinner" />
                                            Processing on the server…
                                        </>
                                    ) : (
                                        `Uploading… ${progress}%`
                                    )}
                                </small>
                            ) : (
                                <small className={cn(tooLarge && 'filefield-over')}>
                                    {formatBytes(file.size)}
                                    {tooLarge && ` — over the ${maxSizeMb} MB limit`}
                                </small>
                            )}

                            {uploading && (
                                <span
                                    className={cn('filefield-bar', settling && 'is-settling')}
                                    role="progressbar"
                                    aria-valuenow={progress}
                                    aria-valuemin={0}
                                    aria-valuemax={100}
                                    aria-label="Upload progress"
                                >
                                    <span style={{ width: `${progress}%` }} />
                                </span>
                            )}
                        </span>

                        {/* Removing mid-upload would leave the request in
                            flight with nothing to show for it. */}
                        {!uploading && (
                            <button
                                type="button"
                                className="filefield-clear"
                                onClick={clear}
                                aria-label="Remove the selected file"
                                title="Remove"
                            >
                                <i className="ti ti-x" />
                            </button>
                        )}
                    </div>
                ) : (
                    <label htmlFor={name} className="filefield-prompt">
                        <span className="filefield-icon">
                            <i className="ti ti-cloud-upload" />
                        </span>

                        <span className="filefield-copy">
                            <b>{placeholder}</b>
                            <small>
                                {describeAccept(accept)} · up to {maxSizeMb} MB
                            </small>
                        </span>
                    </label>
                )}
            </div>

            {showStored && (
                <div className="filefield-current">
                    <PreviewableImage
                        src={previewUrl}
                        caption={label}
                        className="filefield-stored"
                        width={64}
                    />
                    <small className="text-muted">
                        Current image. Choosing a new one replaces it.
                    </small>
                </div>
            )}
        </FieldShell>
    );
}

export function SwitchField<T extends FieldValues>({
    name,
    label,
    register,
    errors,
    hint,
    className,
    description,
}: BaseFieldProps<T> & { description?: string }) {
    const error = messageFor(errors, name);

    return (
        <div className={cn('mb-3', className)}>
            <div className="form-check form-switch">
                <input id={name} type="checkbox" className="form-check-input" {...register(name)} />
                <label className="form-check-label" htmlFor={name}>
                    {description ?? label}
                </label>
            </div>

            {error ? (
                <div className="invalid-feedback d-block">{error}</div>
            ) : (
                hint && <small className="text-muted d-block mt-1">{hint}</small>
            )}
        </div>
    );
}

/** Renders the form-level error set by useApiForm when a request fails. */
export function FormError({ message }: { message?: string }) {
    if (!message) {
        return null;
    }

    return (
        <div className="alert alert-danger py-2 px-3 fs-13" role="alert">
            {message}
        </div>
    );
}
