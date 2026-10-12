import { useEffect, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Button } from '@/shared/components/ui/Button';
import { resourceKey } from '@/shared/hooks/useResource';
import { notify } from '@/shared/utils/notify';
import { cn } from '@/shared/utils/cn';
import { organizationsApi, retryOrganizationProvisioning, useOrganizationProvisioning } from '../api';
import type { Organization, ProvisioningStep } from '../types';

const UNFINISHED = ['pending', 'provisioning', 'failed'];

/** How long a queued job may sit untouched before the page says why. */
const WORKER_GRACE_MS = 10_000;

const STEP_ICONS: Record<ProvisioningStep['status'], string> = {
    waiting: 'ti ti-circle-dashed',
    pending: 'ti ti-circle-dashed',
    in_progress: 'ti ti-loader-2',
    completed: 'ti ti-circle-check',
    failed: 'ti ti-alert-circle',
};

function seconds(from: string | null, to: string | number | null): string {
    if (!from) {
        return '';
    }

    const end = typeof to === 'number' ? to : to ? Date.parse(to) : Date.now();
    const total = Math.max(0, Math.round((end - Date.parse(from)) / 1000));

    return total < 60 ? `${total}s` : `${Math.floor(total / 60)}m ${total % 60}s`;
}

/**
 * Setting a new organization up, watched live.
 *
 * The database, its hundred-odd migrations, the roles, the owner's login and
 * the welcome email are made on the `provisioning` queue after the
 * organization is created, so the screen that created it can return at once.
 * This is where the administrator follows it: each step as it lands, the
 * migrations counted one by one, and — if a step fails — what went wrong and
 * a retry that resumes from that step.
 *
 * Shown while the organization is unfinished, and kept on screen once it
 * finishes so the administrator sees it complete rather than vanish.
 */
export function ProvisioningPanel({ organization }: { organization: Organization }) {
    const unfinished = UNFINISHED.includes(organization.status);
    const [watching, setWatching] = useState(unfinished);
    const [now, setNow] = useState(() => Date.now());
    const client = useQueryClient();

    useEffect(() => {
        if (unfinished) {
            setWatching(true);
        }
    }, [unfinished]);

    const { data: progress } = useOrganizationProvisioning(organization.uuid, watching);

    const running = progress !== undefined && !['active', 'failed'].includes(progress.status);

    // A clock for the elapsed time, only while there is something running.
    useEffect(() => {
        if (!running) {
            return;
        }

        const timer = window.setInterval(() => setNow(Date.now()), 1000);

        return () => window.clearInterval(timer);
    }, [running]);

    /*
     * The organization record on this page (status badge, database tile)
     * catches up the moment provisioning reaches an end, rather than on the
     * next visit.
     */
    useEffect(() => {
        if (progress && progress.status !== organization.status) {
            void client.invalidateQueries({ queryKey: resourceKey(organizationsApi.endpoint) });
        }
    }, [progress?.status, organization.status, client, progress]);

    const retry = useMutation({
        mutationFn: () => retryOrganizationProvisioning(organization.uuid),
        onSuccess: () => notify.success('Queued again — it resumes from the step that failed'),
    });

    if (!watching || !progress) {
        return null;
    }

    const migrate = progress.steps.find((step) => step.key === 'migrate_tenant');
    const migrateShare =
        migrate?.status === 'in_progress' && migrate.progress_total
            ? (migrate.progress_current ?? 0) / migrate.progress_total
            : 0;
    const percent = Math.round(((progress.completed_steps + migrateShare) / progress.total_steps) * 100);

    const failedStep = progress.steps.find((step) => step.status === 'failed');
    const queuedFor = progress.queued_at ? now - Date.parse(progress.queued_at) : 0;
    const noWorker = progress.waiting_for_worker && queuedFor > WORKER_GRACE_MS;

    const headline =
        progress.status === 'active'
            ? 'Ready'
            : progress.status === 'failed'
              ? 'Setup stopped'
              : progress.waiting_for_worker
                ? 'Queued'
                : 'Setting up';

    const summary =
        progress.status === 'active'
            ? `Finished in ${seconds(progress.started_at, progress.finished_at)}. The owner can sign in now.`
            : progress.status === 'failed'
              ? 'Nothing was lost. Fix the cause and retry; finished steps are not repeated.'
              : progress.waiting_for_worker
                ? 'Waiting for the provisioning worker to pick it up.'
                : `Running for ${seconds(progress.started_at, now)}. You can leave this page; it carries on.`;

    return (
        <section className={cn('prov', `is-${progress.status}`)} aria-live="polite">
            <header className="prov-head">
                <span className="prov-badge" aria-hidden="true">
                    <i
                        className={cn(
                            progress.status === 'active'
                                ? 'ti ti-circle-check'
                                : progress.status === 'failed'
                                  ? 'ti ti-alert-triangle'
                                  : 'ti ti-loader-2 prov-spin',
                        )}
                    />
                </span>
                <div className="prov-title">
                    <h5>
                        {headline} &middot; {organization.organization_name}
                    </h5>
                    <p>{summary}</p>
                </div>
                <span className="prov-count">
                    {progress.completed_steps} / {progress.total_steps} steps
                </span>
            </header>

            <div
                className="prov-bar"
                role="progressbar"
                aria-valuemin={0}
                aria-valuemax={100}
                aria-valuenow={percent}
                aria-label="Setup progress"
            >
                <span style={{ width: `${progress.status === 'active' ? 100 : percent}%` }} />
            </div>

            {noWorker && (
                <div className="prov-hint">
                    <i className="ti ti-info-circle" aria-hidden="true" />
                    <div>
                        No worker has picked this up yet. The provisioning worker must be running on the server:
                        <code>php artisan queue:work provisioning --queue=provisioning</code>
                    </div>
                </div>
            )}

            <ol className="prov-steps">
                {progress.steps.map((step) => (
                    <li key={step.key} className={`is-${step.status}`}>
                        <i
                            className={cn(STEP_ICONS[step.status], step.status === 'in_progress' && 'prov-spin')}
                            aria-hidden="true"
                        />
                        <div className="prov-step">
                            <span className="prov-step-label">{step.label}</span>

                            {step.progress_total != null && step.status !== 'waiting' && (
                                <span className="prov-step-sub">
                                    {step.progress_current ?? 0} of {step.progress_total} migrations
                                    {step.status === 'in_progress' && step.detail && (
                                        <code className="prov-detail">{step.detail}</code>
                                    )}
                                </span>
                            )}

                            {step.status === 'failed' && step.error && (
                                <span className="prov-step-error">{step.error}</span>
                            )}
                        </div>
                        <span className="prov-time">
                            {step.status === 'completed' && seconds(step.started_at, step.finished_at)}
                            {step.status === 'in_progress' && seconds(step.started_at, now)}
                        </span>
                    </li>
                ))}
            </ol>

            {progress.status === 'failed' && (
                <footer className="prov-foot">
                    <span>
                        {failedStep ? (
                            <>
                                <b>{failedStep.label}</b> did not finish.
                            </>
                        ) : (
                            'Setup did not finish.'
                        )}
                    </span>
                    <Button icon="ti ti-refresh" loading={retry.isPending} onClick={() => retry.mutate()}>
                        Retry from this step
                    </Button>
                </footer>
            )}
        </section>
    );
}
