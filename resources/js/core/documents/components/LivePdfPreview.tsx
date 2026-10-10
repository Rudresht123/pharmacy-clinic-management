import { useEffect, useRef, useState } from 'react';
import { resolveErrorMessage } from '@/shared/api/http';
import { previewTemplate, type TemplateConfig } from '../templates';

/**
 * The real PDF, rendered by the same engine as the patient's copy and
 * refreshed a moment after each edit.
 *
 * A hand-drawn sketch of the layout used to stand here; it drifted from what
 * actually printed, so the preview showed a document nobody was going to get.
 * The last good render stays on screen while the next one is built.
 */
export function LivePdfPreview({
    documentType,
    config,
    locationId,
    version,
}: {
    documentType: string;
    config: TemplateConfig | null;
    locationId: number | null | undefined;
    /** Changes when something outside the config alters the print — a logo. */
    version?: string;
}) {
    const [url, setUrl] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const latest = useRef(0);
    const shown = useRef<string | null>(null);
    const key = config ? JSON.stringify(config) : '';

    useEffect(() => {
        if (!config) return;

        const request = ++latest.current;
        setBusy(true);

        // Typing is not re-rendered keystroke by keystroke.
        const timer = window.setTimeout(async () => {
            try {
                const next = await previewTemplate(documentType, config, locationId);

                if (request !== latest.current) {
                    URL.revokeObjectURL(next);
                    return;
                }

                if (shown.current) URL.revokeObjectURL(shown.current);
                shown.current = next;
                setUrl(next);
                setError(null);
            } catch (failure) {
                if (request === latest.current) setError(resolveErrorMessage(failure));
            } finally {
                if (request === latest.current) setBusy(false);
            }
        }, 600);

        return () => window.clearTimeout(timer);
        // `key` stands in for `config`, so an identical draft does not re-render.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [documentType, key, locationId, version]);

    useEffect(
        () => () => {
            if (shown.current) URL.revokeObjectURL(shown.current);
        },
        [],
    );

    return (
        <div className="dt-live">
            {url ? (
                <iframe
                    key={url}
                    title="Document preview"
                    className="dt-live-frame"
                    src={`${url}#toolbar=0&navpanes=0&view=Fit`}
                />
            ) : (
                !error && (
                    <div className="dt-live-empty">
                        <i className="ti ti-loader-2 dt-live-spin" aria-hidden="true" />
                        <span>Rendering the preview…</span>
                    </div>
                )
            )}

            {busy && url && (
                <div className="dt-live-busy">
                    <i className="ti ti-loader-2 dt-live-spin" aria-hidden="true" />
                    Updating
                </div>
            )}

            {error && (
                <div className="dt-live-error">
                    <i className="ti ti-alert-triangle" aria-hidden="true" />
                    <span>{error}</span>
                </div>
            )}
        </div>
    );
}
