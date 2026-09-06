import { useEffect, useRef, useState } from 'react';
import { useController, type Control, type FieldValues, type Path } from 'react-hook-form';

export interface Option {
    value: string;
    label: string;
}

/**
 * Several from a list, chosen and shown as chips.
 *
 * A native `<select multiple>` is the wrong control for this: it needs
 * ctrl-click to pick a second entry, shows about four rows whatever the list
 * is, and gives no sign of what is chosen once it scrolls. Chips make the
 * answer readable without opening anything, which matters because this is
 * mostly read rather than edited.
 *
 * Controlled, because the value is an array and `register` cannot express
 * that — which is also why it takes `control` rather than the register the
 * other inputs use.
 */
export function MultiSelectField<T extends FieldValues>({
    name,
    label,
    control,
    options,
    required,
    hint,
    placeholder = 'Choose…',
    error,
}: {
    name: Path<T>;
    label: string;
    control: Control<T>;
    options: Option[];
    required?: boolean;
    hint?: string;
    placeholder?: string;
    error?: string;
}) {
    const { field } = useController({ name, control });

    const [open, setOpen] = useState(false);
    const box = useRef<HTMLDivElement>(null);

    /*
     * Whatever is in the form, as a list.
     *
     * Defensive because this field was a string until a migration ago, and a
     * cached response or a half-migrated row would otherwise crash the form
     * rather than show an empty selection.
     */
    const raw: unknown = field.value;

    const chosen: string[] = Array.isArray(raw)
        ? (raw as string[])
        : typeof raw === 'string' && raw
          ? raw.split(',').map((part) => part.trim())
          : [];

    useEffect(() => {
        if (!open) {
            return;
        }

        function onOutside(event: MouseEvent) {
            if (box.current && !box.current.contains(event.target as Node)) {
                setOpen(false);
            }
        }

        function onKey(event: KeyboardEvent) {
            if (event.key === 'Escape') {
                setOpen(false);
            }
        }

        document.addEventListener('mousedown', onOutside);
        document.addEventListener('keydown', onKey);

        return () => {
            document.removeEventListener('mousedown', onOutside);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    function toggle(value: string) {
        field.onChange(
            chosen.includes(value)
                ? chosen.filter((entry) => entry !== value)
                : [...chosen, value],
        );
    }

    return (
        <div className="mb-3" ref={box}>
            <label className="form-label" htmlFor={name}>
                {label}
                {required && <span className="text-danger ms-1">*</span>}
            </label>

            <div className="ms-box">
                <button
                    type="button"
                    id={name}
                    className={`ms-control${error ? ' is-invalid' : ''}`}
                    aria-haspopup="listbox"
                    aria-expanded={open}
                    onClick={() => setOpen((was) => !was)}
                >
                    {chosen.length === 0 ? (
                        <span className="ms-placeholder">{placeholder}</span>
                    ) : (
                        <span className="ms-chips">
                            {chosen.map((value) => (
                                <span className="ms-chip" key={value}>
                                    {options.find((option) => option.value === value)?.label ??
                                        value}

                                    {/*
                                        A span, not a button. A button inside a
                                        button is invalid markup and the browser
                                        un-nests it, which drops the click
                                        handler that removes the chip.
                                    */}
                                    <span
                                        role="button"
                                        tabIndex={-1}
                                        aria-label={`Remove ${value}`}
                                        onClick={(event) => {
                                            event.stopPropagation();
                                            toggle(value);
                                        }}
                                    >
                                        <i className="ti ti-x" aria-hidden="true" />
                                    </span>
                                </span>
                            ))}
                        </span>
                    )}

                    <i
                        className={open ? 'ti ti-chevron-up' : 'ti ti-chevron-down'}
                        aria-hidden="true"
                    />
                </button>

                {open && (
                    <div className="ms-list" role="listbox" aria-multiselectable="true">
                        {options.length === 0 ? (
                            <p className="ms-empty">
                                No options yet. Add them under Settings → Fields.
                            </p>
                        ) : (
                            options.map((option) => (
                                <button
                                    type="button"
                                    key={option.value}
                                    role="option"
                                    aria-selected={chosen.includes(option.value)}
                                    className={`ms-option${chosen.includes(option.value) ? ' is-on' : ''}`}
                                    onClick={() => toggle(option.value)}
                                >
                                    <i
                                        className={
                                            chosen.includes(option.value)
                                                ? 'ti ti-square-check-filled'
                                                : 'ti ti-square'
                                        }
                                        aria-hidden="true"
                                    />
                                    {option.label}
                                </button>
                            ))
                        )}
                    </div>
                )}
            </div>

            {error ? (
                <div className="invalid-feedback d-block">{error}</div>
            ) : (
                hint && <small className="text-muted d-block mt-1">{hint}</small>
            )}
        </div>
    );
}
