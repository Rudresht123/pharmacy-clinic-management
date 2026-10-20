import { useEffect, useRef, useState } from 'react';
import { notify } from '@/shared/utils/notify';
import { resolveErrorMessage } from '@/shared/api/http';
import { downloadRegister, type RegisterFormat } from '../api';

const FORMATS: { format: RegisterFormat; label: string; hint: string; icon: string }[] = [
    { format: 'xlsx', label: 'Excel', hint: 'Ready to sort and filter', icon: 'ti ti-file-spreadsheet' },
    { format: 'csv', label: 'CSV', hint: 'For any other software', icon: 'ti ti-file-type-csv' },
    { format: 'pdf', label: 'PDF', hint: 'Ready to print', icon: 'ti ti-file-type-pdf' },
];

/**
 * "Export" — the register as a file, with the search and filters on screen:
 * filtered, the file holds the patients that match; unfiltered, all of them.
 * The menu says which, with the count, before anything downloads.
 *
 * A menu rather than three buttons: the header already has its primary action,
 * and a format is a second choice, not a first one.
 */
export function ExportMenu({
    params,
    count,
    noun,
}: {
    params: Record<string, string | number | undefined>;
    /** How many the list holds now — what the file will hold. */
    count?: number;
    /** What a row is called here, plural: "patients". */
    noun: string;
}) {
    const filtered = Object.values(params).some((value) => value !== undefined && value !== '');
    const shown = count?.toLocaleString('en-IN');
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState<RegisterFormat | null>(null);
    const ref = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) return;

        const close = (event: MouseEvent) => {
            if (!ref.current?.contains(event.target as Node)) setOpen(false);
        };

        document.addEventListener('mousedown', close);

        return () => document.removeEventListener('mousedown', close);
    }, [open]);

    async function pick(format: RegisterFormat) {
        setOpen(false);
        setBusy(format);

        try {
            await downloadRegister(params, format);
        } catch (failure) {
            notify.error(resolveErrorMessage(failure));
        } finally {
            setBusy(null);
        }
    }

    return (
        <div className="ptr-export" ref={ref}>
            <button
                type="button"
                className="btn ptr-btn-quiet"
                onClick={() => setOpen((was) => !was)}
                aria-haspopup="menu"
                aria-expanded={open}
                disabled={busy !== null}
            >
                <i className={busy ? 'ti ti-loader-2 ptr-spin' : 'ti ti-download'} aria-hidden="true" />
                {busy ? `Preparing ${FORMATS.find((entry) => entry.format === busy)?.label}…` : 'Export'}
                {!busy && <i className="ti ti-chevron-down ptr-export-caret" aria-hidden="true" />}
            </button>

            {open && (
                <div className="ptr-menu ptr-export-menu" role="menu">
                    {shown !== undefined && (
                        <p className="ptr-export-scope">
                            <i className={filtered ? 'ti ti-filter' : 'ti ti-list-check'} aria-hidden="true" />
                            {filtered ? `${shown} ${noun} matching filters` : `All ${shown} ${noun}`}
                        </p>
                    )}
                    {FORMATS.map((entry) => (
                        <button key={entry.format} type="button" role="menuitem" onClick={() => void pick(entry.format)}>
                            <i className={`${entry.icon} is-${entry.format}`} aria-hidden="true" />
                            <span>
                                <b>{entry.label}</b>
                                <small>{entry.hint}</small>
                            </span>
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}
