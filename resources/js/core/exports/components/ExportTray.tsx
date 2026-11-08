import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { resolveErrorMessage } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import { cn } from '@/shared/utils/cn';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { autoDownload, downloadRun, isActive, useCancelRun, useDismissRun, useExportRuns } from '../runs';
import type { ExportRun } from '../types';

/** The ticks that give any Export Center tab. */
const EXPORT_TICKS = ['exports.patients', 'exports.pharmacy', 'exports.billing', 'exports.communication'];

const FORMAT: Record<ExportRun['format'], { label: string; icon: string }> = {
    xlsx: { label: 'Excel', icon: 'ti ti-file-spreadsheet' },
    csv: { label: 'CSV', icon: 'ti ti-file-type-csv' },
    pdf: { label: 'PDF', icon: 'ti ti-file-type-pdf' },
};

const count = (value: number) => value.toLocaleString('en-IN');

/** "about 2 min left" — rounded the way a person would say it. */
function timeLeft(seconds: number): string {
    if (seconds < 10) return 'a few seconds left';
    if (seconds < 60) return `about ${Math.ceil(seconds / 5) * 5} sec left`;

    const minutes = Math.round(seconds / 60);

    if (minutes < 60) return `about ${minutes} min left`;

    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    return `about ${hours} h${rest ? ` ${rest} min` : ''} left`;
}

function size(bytes: number | null): string | null {
    if (bytes === null) return null;
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/** What one line under the title says, by where the run stands. */
function describe(run: ExportRun): string {
    switch (run.status) {
        case 'queued':
            return `Waiting to start · ${count(run.total_rows)} ${run.noun}`;
        case 'running':
            if (run.finishing) return 'Finishing the file…';

            return [
                `${count(run.processed_rows)} of ${count(run.total_rows)} ${run.noun}`,
                run.seconds_left !== null ? timeLeft(run.seconds_left) : 'working out the time left',
            ].join(' · ');
        case 'completed':
            return ['Ready', size(run.file_size), run.downloaded_at ? 'downloaded' : null].filter(Boolean).join(' · ');
        case 'failed':
            return run.error ?? 'The file could not be made. Try again.';
        case 'cancelled':
            return 'Cancelled';
        default:
            return 'No longer kept — export it again';
    }
}

/**
 * The files being made in the background, on every screen.
 *
 * Starting an export puts it here; the person carries on with their work —
 * other screens included — and watches it move: rows written, about how long
 * is left, then Ready, when it downloads by itself (if this tab started it).
 * Folded to a small pill when they want it out of the way; gone when there is
 * nothing to show. Finished files are kept a day.
 */
export function ExportTray() {
    const { can } = useTenantAuth();
    const allowed = EXPORT_TICKS.some(can);

    const { data: runs = [] } = useExportRuns(allowed);
    const cancel = useCancelRun();
    const dismiss = useDismissRun();

    const [folded, setFolded] = useState(false);
    const [taking, setTaking] = useState<number | null>(null);
    const seen = useRef(new Map<number, ExportRun['status']>());

    async function take(run: ExportRun) {
        setTaking(run.id);

        try {
            await downloadRun(run);
        } catch (failure) {
            notify.error('The file could not be downloaded', { description: resolveErrorMessage(failure) });
        } finally {
            setTaking(null);
        }
    }

    /*
     * A run this tab started, finishing: download it, once. A failure says
     * so. Judged on the change of status, so a reload does not repeat it.
     */
    useEffect(() => {
        runs.forEach((run) => {
            const before = seen.current.get(run.id);
            seen.current.set(run.id, run.status);

            if (!autoDownload.has(run.id) || isActive(run)) return;

            autoDownload.remove(run.id);

            if (run.status === 'completed' && !run.downloaded_at) {
                setFolded(false);
                notify.success(`${run.title} is ready`, { description: `Your ${FORMAT[run.format].label} file is downloading.` });
                void take(run);
            } else if (run.status === 'failed' && before !== undefined) {
                setFolded(false);
                notify.error(`${run.title} could not be exported`, { description: run.error ?? undefined });
            }
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [runs]);

    if (!allowed || runs.length === 0) return null;

    const active = runs.filter(isActive);
    const overall = active.length
        ? Math.round(active.reduce((sum, run) => sum + run.percent, 0) / active.length)
        : null;

    if (folded) {
        return (
            <button type="button" className="xtr-pill" onClick={() => setFolded(false)} aria-label="Show exports">
                <i className={active.length ? 'ti ti-loader-2 xtr-spin' : 'ti ti-file-check'} aria-hidden="true" />
                {active.length
                    ? `Exporting ${active.length} file${active.length === 1 ? '' : 's'} · ${overall}%`
                    : `${runs.length} export${runs.length === 1 ? '' : 's'} ready`}
            </button>
        );
    }

    return (
        <section className="xtr" aria-label="Exports" aria-live="polite">
            <header className="xtr-head">
                <span className="xtr-head-icon" aria-hidden="true">
                    <i className="ti ti-file-export" />
                </span>
                <div className="xtr-head-text">
                    <b>Exports</b>
                    <small>
                        {active.length
                            ? `${active.length} being prepared — keep working, they download when ready`
                            : 'Files are kept for 24 hours'}
                    </small>
                </div>
                <button type="button" className="xtr-icon-btn" onClick={() => setFolded(true)} aria-label="Fold the exports away" title="Fold away">
                    <i className="ti ti-chevron-down" aria-hidden="true" />
                </button>
            </header>

            <ul className="xtr-list">
                {runs.map((run) => {
                    const working = isActive(run);
                    const format = FORMAT[run.format];

                    return (
                        <li key={run.id} className={cn('xtr-run', `is-${run.status}`)}>
                            <span className={cn('xtr-format', `is-${run.format}`)} title={format.label} aria-hidden="true">
                                <i className={format.icon} />
                            </span>

                            <div className="xtr-body">
                                <div className="xtr-title-row">
                                    <b title={run.title}>{run.title}</b>
                                    {working && <span className="xtr-percent">{run.status === 'queued' ? '—' : `${run.percent}%`}</span>}
                                </div>

                                {working && (
                                    <div
                                        className={cn('xtr-bar', (run.status === 'queued' || run.finishing) && 'is-waiting')}
                                        role="progressbar"
                                        aria-valuemin={0}
                                        aria-valuemax={100}
                                        aria-valuenow={run.percent}
                                        aria-label={`${run.title} progress`}
                                    >
                                        <span style={{ width: `${run.status === 'queued' ? 100 : Math.max(run.percent, 3)}%` }} />
                                    </div>
                                )}

                                <small className="xtr-line" title={describe(run)}>
                                    {describe(run)}
                                </small>
                            </div>

                            <div className="xtr-actions">
                                {run.status === 'completed' && (
                                    <button
                                        type="button"
                                        className="xtr-get"
                                        onClick={() => void take(run)}
                                        disabled={taking === run.id}
                                        title={run.file_name ?? 'Download'}
                                    >
                                        <i className={taking === run.id ? 'ti ti-loader-2 xtr-spin' : 'ti ti-download'} aria-hidden="true" />
                                        Download
                                    </button>
                                )}

                                {working ? (
                                    <button
                                        type="button"
                                        className="xtr-icon-btn"
                                        onClick={() => cancel.mutate(run.id)}
                                        disabled={cancel.isPending}
                                        aria-label={`Cancel ${run.title}`}
                                        title="Cancel"
                                    >
                                        <i className="ti ti-player-stop" aria-hidden="true" />
                                    </button>
                                ) : (
                                    <button
                                        type="button"
                                        className="xtr-icon-btn"
                                        onClick={() => dismiss.mutate(run.id)}
                                        aria-label={`Clear ${run.title}`}
                                        title={run.status === 'completed' ? 'Clear — deletes the file' : 'Clear'}
                                    >
                                        <i className="ti ti-x" aria-hidden="true" />
                                    </button>
                                )}
                            </div>
                        </li>
                    );
                })}
            </ul>

            <footer className="xtr-foot">
                <Link to="/exports">
                    <i className="ti ti-arrow-right" aria-hidden="true" /> Open the Export Center
                </Link>
            </footer>
        </section>
    );
}
