import { useEffect, useMemo, useState, type CSSProperties, type ReactNode } from 'react';
import { useSearchParams } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Button } from '@/shared/components/ui/Button';
import { Modal } from '@/shared/components/ui/Modal';
import { ErrorState, LoadingBlock } from '@/shared/components/ui/Feedback';
import { notify } from '@/shared/utils/notify';
import { resolveErrorMessage } from '@/shared/api/http';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import {
    quoteAddons,
    useBuyAddons,
    usePayInvoice,
    useRenew,
    useSubscription,
    useSubscriptionCatalogue,
    useSubscriptionInvoices,
    useUpgrade,
    verifyPayment,
} from '../api';
import { openCheckout } from '../razorpay';
import { formatPaise, formatRupees, formatShortDay } from '../money';
import { stateOf } from '../components/SubscriptionStatusCard';
import type {
    CatalogueEntry,
    CheckoutResponse,
    QuotaUsage,
    ResourceUsage,
    SubscriptionInvoice,
    SubscriptionOverview,
    SubscriptionStatus,
} from '../types';

type CataloguePlan = CatalogueEntry['plans'][number];
type PlanMode = 'compare' | 'renew' | 'upgrade';
type Cycle = 'monthly' | 'annual';
type Preselect = { addon?: string; module?: string };
type Dialog =
    | null
    | { kind: 'plans'; mode: PlanMode }
    | { kind: 'addons'; preselect?: Preselect }
    | { kind: 'details' }
    | { kind: 'payment' }
    | { kind: 'invoice'; invoice: SubscriptionInvoice }
    | { kind: 'offline'; response: CheckoutResponse };

/** The inbox the sidebar's Help & Support link writes to. */
const BILLING_MAIL = `mailto:support@hms.local?subject=${encodeURIComponent('Billing question')}`;

/**
 * The organization's own subscription: plan, dates, what it is using, what it
 * has bought, what it owes — and renewing, upgrading and buying add-ons.
 *
 * Every number on the page is from the server. Buying anything goes: server
 * quote → invoice → Razorpay checkout → the server verifies the payment →
 * the subscription changes.
 */
export default function SubscriptionPage() {
    const { subscription: session, organization, refreshSession } = useTenantAuth();
    const allowed = !(session && !session.can_manage);
    const { data, isLoading, isError, refetch } = useSubscription();
    const { data: catalogue } = useSubscriptionCatalogue(allowed);
    const invoices = useSubscriptionInvoices(allowed);
    const [params, setParams] = useSearchParams();
    const [dialog, setDialog] = useState<Dialog>(null);
    const [paying, setPaying] = useState(false);
    const labels = useMemo(() => limitLabels(data), [data]);

    // ?action= from the dashboard card and the banner.
    useEffect(() => {
        const action = params.get('action');

        if (action === 'renew' || action === 'upgrade' || action === 'compare') {
            setDialog({ kind: 'plans', mode: action });
        } else if (action === 'addons') {
            setDialog({ kind: 'addons' });
        } else {
            return;
        }

        params.delete('action');
        setParams(params, { replace: true });
    }, [params, setParams]);

    if (!allowed) {
        return (
            <div className="org-pending">
                <i className="ti ti-lock" />
                <h6>Only the owner can manage the subscription</h6>
                <p>Ask your organization's owner, or a billing contact, to renew or change it.</p>
            </div>
        );
    }

    async function pay(response: CheckoutResponse) {
        if (!response.checkout) {
            setDialog({ kind: 'offline', response });

            return;
        }

        setPaying(true);

        try {
            const result = await openCheckout(response.checkout, organization?.name);

            if (!result) {
                notify.info('Payment not completed. The invoice stays open — you can pay it any time.');

                return;
            }

            const verified = await verifyPayment(result);

            notify.success(verified.message ?? 'Payment received. Thank you!');
            setDialog(null);
            await refreshSession();
            await refetch();
        } catch (error) {
            notify.error(resolveErrorMessage(error));
        } finally {
            setPaying(false);
        }
    }

    const status = data?.status;
    const currentPlan = catalogue?.plans.find((plan) => plan.is_current);
    const canAct = Boolean(status && !status.is_complimentary);
    const canUpgrade = !catalogue || upgradeOptions(catalogue.plans, status).length > 0;
    const openPlans = (mode: PlanMode) => setDialog({ kind: 'plans', mode });

    return (
        <>
            <PageHeader
                title="Subscription & Plan"
                subtitle="Manage your plan, usage, add-ons and billing"
                icon="ti ti-crown"
                crumbs={[{ label: 'Subscription' }]}
                actions={
                    canAct && status ? (
                        <div className="d-flex flex-wrap gap-2">
                            {!status.locked && (
                                <Button variant="light" icon="ti ti-arrows-right-left" onClick={() => openPlans('compare')}>
                                    Compare plans
                                </Button>
                            )}
                            {!status.locked && canUpgrade ? (
                                <Button icon="ti ti-arrow-up" onClick={() => openPlans('upgrade')}>
                                    Upgrade plan
                                </Button>
                            ) : (
                                <Button icon="ti ti-refresh" onClick={() => openPlans('renew')}>
                                    {status.locked ? 'Renew now' : 'Renew plan'}
                                </Button>
                            )}
                        </div>
                    ) : undefined
                }
            />

            {isLoading && <LoadingBlock />}
            {isError && <ErrorState onRetry={() => void refetch()} />}

            {data && (
                <div className="sxp">
                    {data.open_invoices.map((invoice) => (
                        <DueAlert key={invoice.uuid} invoice={invoice} timezone={data.status.timezone} busy={paying} onPay={pay} />
                    ))}

                    <HeroCard
                        overview={data}
                        onDetails={() => setDialog({ kind: 'details' })}
                        onRenew={() => openPlans('renew')}
                    />

                    <UsageCard
                        overview={data}
                        catalogue={catalogue}
                        canAct={canAct}
                        canUpgrade={canUpgrade}
                        onAddons={() => setDialog({ kind: 'addons' })}
                        onCompare={() => openPlans('compare')}
                    />

                    <div className="sxp-row">
                        <ModulesCard overview={data} plan={currentPlan} />
                        <AddonsCard
                            overview={data}
                            catalogue={catalogue}
                            labels={labels}
                            canAct={canAct}
                            onAdd={(preselect) => setDialog({ kind: 'addons', preselect })}
                        />
                    </div>

                    <BillingCard
                        invoices={invoices.data ?? []}
                        loading={invoices.isLoading}
                        timezone={data.status.timezone}
                        busy={paying}
                        onPay={pay}
                        onView={(invoice) => setDialog({ kind: 'invoice', invoice })}
                        onPaymentMethods={() => setDialog({ kind: 'payment' })}
                    />
                </div>
            )}

            {data && dialog?.kind === 'plans' && (
                <PlanDialog
                    mode={dialog.mode}
                    status={data.status}
                    catalogue={catalogue}
                    labels={labels}
                    busy={paying}
                    onClose={() => setDialog(null)}
                    onCheckout={pay}
                />
            )}
            {data && dialog?.kind === 'addons' && (
                <AddonsDialog
                    cycle={data.status.billing_cycle}
                    preselect={dialog.preselect}
                    busy={paying}
                    onClose={() => setDialog(null)}
                    onCheckout={pay}
                />
            )}
            {data && dialog?.kind === 'details' && (
                <PlanDetailsDialog
                    overview={data}
                    plan={currentPlan}
                    labels={labels}
                    canAct={canAct && !data.status.locked}
                    onClose={() => setDialog(null)}
                    onCompare={() => openPlans('compare')}
                />
            )}
            {data && dialog?.kind === 'payment' && (
                <PaymentMethodsDialog online={data.payment.online} onClose={() => setDialog(null)} />
            )}
            {data && dialog?.kind === 'invoice' && (
                <InvoiceDialog
                    invoice={dialog.invoice}
                    timezone={data.status.timezone}
                    busy={paying}
                    onClose={() => setDialog(null)}
                    onPay={pay}
                />
            )}
            {dialog?.kind === 'offline' && (
                <Modal
                    open
                    title="Invoice issued"
                    icon={<i className="ti ti-file-invoice" />}
                    onClose={() => setDialog(null)}
                    footer={<Button onClick={() => setDialog(null)}>Done</Button>}
                >
                    <p>
                        Invoice <strong>{dialog.response.invoice.number}</strong> for{' '}
                        <strong>{money(dialog.response.invoice.total, dialog.response.invoice.currency)}</strong> is ready.
                    </p>
                    <p className="text-muted mb-0">{dialog.response.offline}</p>
                </Modal>
            )}
        </>
    );
}

/* ----- shared bits ------------------------------------------------------- */

/** ₹1,000 for whole rupees, ₹1,000.50 otherwise. */
function money(paise: number | null | undefined, currency = 'INR'): string {
    if (paise !== null && paise !== undefined && currency === 'INR' && paise % 100 === 0) {
        return formatRupees(paise);
    }

    return formatPaise(paise, currency);
}

function CardHead({ icon, title, description, aside }: { icon: string; title: string; description?: ReactNode; aside?: ReactNode }) {
    return (
        <header className="sxp-card-head">
            <div className="sxp-card-title">
                <span className="sxp-card-icon" aria-hidden="true">
                    <i className={icon} />
                </span>
                <div>
                    <h3>{title}</h3>
                    {description && <p>{description}</p>}
                </div>
            </div>
            {aside}
        </header>
    );
}

function Empty({ icon, title, children }: { icon: string; title: string; children?: ReactNode }) {
    return (
        <div className="sxp-empty">
            <span className="sxp-empty-icon" aria-hidden="true">
                <i className={icon} />
            </span>
            <b>{title}</b>
            {children}
        </div>
    );
}

const METRICS: Record<string, { icon: string; hue?: string }> = {
    branches: { icon: 'ti ti-building-hospital' },
    doctors: { icon: 'ti ti-stethoscope' },
    employees: { icon: 'ti ti-users' },
    staff: { icon: 'ti ti-users' },
    whatsapp_messages: { icon: 'ti ti-brand-whatsapp', hue: 'green' },
    sms_messages: { icon: 'ti ti-message-2', hue: 'sky' },
    emails: { icon: 'ti ti-mail', hue: 'amber' },
    video_minutes: { icon: 'ti ti-clock-hour-4', hue: 'violet' },
    video_sessions: { icon: 'ti ti-video', hue: 'violet' },
};

function metricOf(key: string | null | undefined): { icon: string; hue?: string } {
    return (key && METRICS[key]) || { icon: 'ti ti-package' };
}

function limitLabels(data?: SubscriptionOverview): Record<string, string> {
    const labels: Record<string, string> = {};

    if (data) {
        Object.values(data.resources).forEach((row) => (labels[row.key] = row.label));
        Object.values(data.usage).forEach((row) => (labels[row.key] = row.label));
    }

    return labels;
}

function limitRows(limits: Record<string, number | null>, labels: Record<string, string>): Array<[string, number | null]> {
    const known = Object.keys(labels).filter((key) => key in limits);
    const other = Object.keys(limits).filter((key) => !(key in labels));

    return [...known, ...other].map((key) => [labels[key] ?? humanize(key), limits[key]]);
}

function humanize(key: string): string {
    const text = key.replace(/_/g, ' ');

    return text.charAt(0).toUpperCase() + text.slice(1);
}

function priceOf(plan: CataloguePlan, cycle: Cycle): number {
    return cycle === 'annual' ? plan.annual_price : plan.monthly_price;
}

/** Plans the clinic can move up to mid-term: dearer than what it pays now. */
function upgradeOptions(plans: CataloguePlan[], status?: SubscriptionStatus): CataloguePlan[] {
    const cycle: Cycle = status?.billing_cycle === 'annual' ? 'annual' : 'monthly';

    return plans.filter((plan) => !plan.is_current && priceOf(plan, cycle) > (status?.price ?? 0));
}

function daysBetween(from?: string | null, to?: string | null): number | null {
    if (!from || !to) {
        return null;
    }

    const days = Math.round((new Date(to).getTime() - new Date(from).getTime()) / 86_400_000);

    return days > 0 ? days : null;
}

/* ----- current plan ------------------------------------------------------ */

function HeroCard({ overview, onDetails, onRenew }: { overview: SubscriptionOverview; onDetails: () => void; onRenew: () => void }) {
    const status = overview.status;
    const state = stateOf(status);
    const urgent = !status.is_complimentary && (status.locked || status.warning || status.status === 'grace' || status.status === 'trial');

    return (
        <section className={`sxp-card sxp-hero is-${state.tone}`} aria-label="Current plan">
            <div className="sxp-hero-plan">
                <span className="sxp-hero-badge" aria-hidden="true">
                    <i className="ti ti-crown" />
                </span>
                <div className="sxp-hero-id">
                    <span className="sxp-eyebrow">Current plan</span>
                    <div className="sxp-hero-name">
                        <h2>{status.plan.name ?? 'Subscription'}</h2>
                        <span className={`sx-chip sx-chip--${state.tone}`}>
                            <i className={state.icon} aria-hidden="true" /> {state.label}
                        </span>
                    </div>
                    {!status.is_complimentary && status.price !== undefined && (
                        <div className="sxp-hero-price">
                            {money(status.price, status.currency)} <small>/ {status.billing_cycle === 'annual' ? 'year' : 'month'}</small>
                        </div>
                    )}
                    <p className="sxp-hero-line">{state.sentence}</p>
                </div>
            </div>

            <div className="sxp-hero-facts">
                <ul className="sxp-facts">
                    {heroFacts(status, overview.payment.online).map((fact) => (
                        <li key={fact.label} className="sxp-fact">
                            <span className="sxp-fact-icon" aria-hidden="true">
                                <i className={fact.icon} />
                            </span>
                            <div>
                                <small>{fact.label}</small>
                                <b>{fact.value}</b>
                            </div>
                        </li>
                    ))}
                </ul>
                <div className="sxp-hero-actions">
                    {urgent && (
                        <Button icon="ti ti-refresh" onClick={onRenew}>
                            {status.status === 'trial' ? 'Choose a plan' : 'Renew now'}
                        </Button>
                    )}
                    <button type="button" className="btn btn-sm sxp-btn-ghost" onClick={onDetails}>
                        <i className="ti ti-file-description" aria-hidden="true" /> View plan details
                    </button>
                </div>
            </div>

            <div className="sxp-hero-ring">
                <HeroRing status={status} />
            </div>
        </section>
    );
}

function heroFacts(status: SubscriptionStatus, online: boolean): Array<{ icon: string; label: string; value: string }> {
    const tz = status.timezone;
    const started = { icon: 'ti ti-calendar-event', label: 'Plan started on', value: formatShortDay(status.started_at ?? status.current_period_start, tz) };

    if (status.is_complimentary) {
        return [started, { icon: 'ti ti-infinity', label: 'Term', value: 'No end date' }];
    }

    const end = status.locked
        ? { icon: 'ti ti-calendar-x', label: 'Ended on', value: formatShortDay(status.expires_at, tz) }
        : status.status === 'grace'
            ? { icon: 'ti ti-alert-triangle', label: 'Grace ends', value: formatShortDay(status.grace_ends_at, tz) }
            : status.status === 'trial'
                ? { icon: 'ti ti-hourglass', label: 'Trial ends', value: formatShortDay(status.trial_ends_at ?? status.expires_at, tz) }
                : status.cancelled_at
                    ? { icon: 'ti ti-calendar-x', label: 'Ends on', value: formatShortDay(status.expires_at, tz) }
                    : { icon: 'ti ti-refresh', label: 'Renews on', value: formatShortDay(status.next_renewal_at ?? status.expires_at, tz) };

    const cycle = status.pending_plan
        ? { icon: 'ti ti-arrow-big-right', label: 'From next term', value: status.pending_plan }
        : { icon: 'ti ti-repeat', label: 'Billing cycle', value: status.billing_cycle === 'annual' ? 'Annual' : 'Monthly' };

    return [
        started,
        end,
        cycle,
        { icon: 'ti ti-credit-card', label: 'Payment method', value: online ? 'Online · UPI, cards' : 'Bank transfer' },
    ];
}

function HeroRing({ status }: { status: SubscriptionStatus }) {
    const state = stateOf(status);

    if (state.count === null) {
        return (
            <Ring percent={status.locked ? 0 : 100}>
                <i className={status.locked ? 'ti ti-lock' : 'ti ti-infinity'} aria-hidden="true" />
                <span>{status.locked ? 'Workspace locked' : 'No end date'}</span>
            </Ring>
        );
    }

    const span =
        status.status === 'grace'
            ? daysBetween(status.expires_at, status.grace_ends_at)
            : daysBetween(status.current_period_start ?? status.started_at, status.expires_at) ?? (status.billing_cycle === 'annual' ? 365 : 30);
    const percent = span ? Math.max(0, Math.min(100, (state.count / span) * 100)) : 100;
    const label = state.tone === 'grace' ? state.countLabel : state.count === 1 ? 'day remaining' : 'days remaining';

    return (
        <Ring percent={percent}>
            <b>{state.count}</b>
            <span>{label}</span>
        </Ring>
    );
}

function Ring({ percent, children }: { percent: number; children: ReactNode }) {
    const radius = 56;
    const circumference = 2 * Math.PI * radius;

    return (
        <div className="sxp-ring">
            <svg viewBox="0 0 132 132" aria-hidden="true">
                <circle cx="66" cy="66" r={radius - 9} className="sxp-ring-disk" />
                <circle cx="66" cy="66" r={radius} className="sxp-ring-track" />
                <circle
                    cx="66"
                    cy="66"
                    r={radius}
                    className="sxp-ring-fill"
                    strokeDasharray={circumference}
                    strokeDashoffset={circumference * (1 - percent / 100)}
                    transform="rotate(-90 66 66)"
                />
            </svg>
            <div className="sxp-ring-text">{children}</div>
        </div>
    );
}

/* ----- usage ------------------------------------------------------------- */

type MeterState = 'ok' | 'near' | 'full' | 'over' | 'none' | 'unlimited';

function meterOf(used: number, limit: number | null): { state: MeterState; percent: number } {
    if (limit === null) {
        return { state: 'unlimited', percent: 0 };
    }

    if (limit === 0) {
        return used > 0 ? { state: 'over', percent: 100 } : { state: 'none', percent: 0 };
    }

    const percent = Math.round((used / limit) * 100);

    return { state: used > limit ? 'over' : used === limit ? 'full' : percent >= 80 ? 'near' : 'ok', percent };
}

function UsageCard({
    overview,
    catalogue,
    canAct,
    canUpgrade,
    onAddons,
    onCompare,
}: {
    overview: SubscriptionOverview;
    catalogue: CatalogueEntry | undefined;
    canAct: boolean;
    canUpgrade: boolean;
    onAddons: () => void;
    onCompare: () => void;
}) {
    const tz = overview.status.timezone;
    const resources = Object.values(overview.resources);
    const quotas = Object.values(overview.usage);
    const periodEnd = quotas[0]?.period_end;
    // The period ends at 23:59:59; it resets the moment after.
    const resets = periodEnd ? formatShortDay(new Date(new Date(periodEnd).getTime() + 1000).toISOString(), tz) : null;
    const hasAddons = (catalogue?.addons.length ?? 0) > 0;
    const capacityCols = resources.length + (canAct ? 1 : 0);

    return (
        <section className="sxp-card" aria-label="Usage overview">
            <CardHead
                icon="ti ti-chart-bar"
                title="Usage overview"
                description={resets ? `Usage resets on ${resets}` : 'What your clinic is using right now'}
            />

            {resources.length > 0 && (
                <div className="sxp-group">
                    <div className="sxp-group-head">
                        <h4>Plan limits</h4>
                        <span>What your plan allows at a time</span>
                    </div>
                    <div className="sxp-tiles" style={{ '--cols': capacityCols } as CSSProperties}>
                        {resources.map((row) => (
                            <ResourceTile key={row.key} row={row} />
                        ))}
                        {canAct && (
                            <div className="sxp-tile sxp-tile--cta">
                                <span className="sxp-tile-icon" aria-hidden="true">
                                    <i className="ti ti-plus" />
                                </span>
                                <b>Need more room?</b>
                                <p>
                                    {hasAddons
                                        ? 'Add branches, doctors or staff without changing your plan.'
                                        : canUpgrade
                                            ? 'Move to a bigger plan for higher limits.'
                                            : 'Our billing team can raise your limits.'}
                                </p>
                                {hasAddons ? (
                                    <button type="button" className="sxp-link" onClick={onAddons}>
                                        Explore add-ons <i className="ti ti-arrow-right" aria-hidden="true" />
                                    </button>
                                ) : canUpgrade ? (
                                    <button type="button" className="sxp-link" onClick={onCompare}>
                                        Compare plans <i className="ti ti-arrow-right" aria-hidden="true" />
                                    </button>
                                ) : (
                                    <a className="sxp-link" href={BILLING_MAIL}>
                                        Contact billing <i className="ti ti-arrow-right" aria-hidden="true" />
                                    </a>
                                )}
                            </div>
                        )}
                    </div>
                </div>
            )}

            {quotas.length > 0 && (
                <div className="sxp-group">
                    <div className="sxp-group-head">
                        <h4>Monthly allowances</h4>
                        {resets && <span>Resets on {resets}</span>}
                    </div>
                    <div className="sxp-tiles sxp-tiles--compact" style={{ '--cols': quotas.length } as CSSProperties}>
                        {quotas.map((row) => (
                            <QuotaTile key={row.key} row={row} />
                        ))}
                    </div>
                </div>
            )}
        </section>
    );
}

function ResourceTile({ row }: { row: ResourceUsage }) {
    const limit = row.unlimited ? null : row.limit;

    return <UsageTile metricKey={row.key} label={row.label} used={row.used} limit={limit} overLabel="Over limit" />;
}

function QuotaTile({ row }: { row: QuotaUsage }) {
    const limit = row.unlimited ? null : row.allowance;
    const extra = row.purchased + row.granted;

    return (
        <UsageTile
            compact
            metricKey={row.key}
            label={row.label}
            used={row.used}
            limit={limit}
            unit={row.unit}
            overLabel="Over allowance"
            note={extra > 0 ? `Includes ${extra.toLocaleString('en-IN')} extra` : undefined}
            foot={
                row.overage > 0
                    ? `${row.overage.toLocaleString('en-IN')} extra · ${money(row.overage_charge)} on next invoice`
                    : undefined
            }
        />
    );
}

function UsageTile({
    metricKey,
    label,
    used,
    limit,
    unit,
    compact = false,
    overLabel,
    note,
    foot,
}: {
    metricKey: string;
    label: string;
    used: number;
    limit: number | null;
    unit?: string;
    compact?: boolean;
    overLabel: string;
    note?: string;
    foot?: string;
}) {
    const { state, percent } = meterOf(used, limit);
    const metric = metricOf(metricKey);
    const chip: [string, string] | null =
        state === 'over' ? ['locked', overLabel] : state === 'full' ? ['grace', 'Limit reached'] : state === 'near' ? ['warn', 'Nearly full'] : null;
    const shown = Math.min(100, percent);
    const left =
        foot ??
        {
            ok: `${((limit ?? 0) - used).toLocaleString('en-IN')} left`,
            near: `${((limit ?? 0) - used).toLocaleString('en-IN')} left`,
            full: 'No room left',
            over: `${(used - (limit ?? 0)).toLocaleString('en-IN')} over the limit`,
            none: 'Not in your plan',
            unlimited: 'Unlimited',
        }[state];

    return (
        <div className={`sxp-tile is-${state}${metric.hue ? ` hue-${metric.hue}` : ''}`}>
            <div className="sxp-tile-top">
                <span className="sxp-tile-icon" aria-hidden="true">
                    <i className={metric.icon} />
                </span>
                <span className="sxp-tile-label">{label}</span>
                {chip && !compact && <span className={`sx-chip sx-chip--${chip[0]}`}>{chip[1]}</span>}
            </div>

            <div className="sxp-tile-value">
                {used.toLocaleString('en-IN')}
                <small>
                    {limit !== null ? ` of ${limit.toLocaleString('en-IN')}` : ''}
                    {unit ? ` ${unit}` : ''}
                </small>
            </div>

            {note && <div className="sxp-tile-note">{note}</div>}
            {chip && compact && <span className={`sx-chip sx-chip--${chip[0]} sxp-tile-chip`}>{chip[1]}</span>}

            <div className="sxp-tile-meter">
                <div
                    className="sxp-bar"
                    role="progressbar"
                    aria-label={`${label} used`}
                    aria-valuemin={0}
                    aria-valuemax={100}
                    aria-valuenow={shown}
                >
                    <span style={{ width: `${state === 'unlimited' ? 100 : shown}%` }} />
                </div>
                <div className="sxp-tile-foot">
                    <b>{left}</b>
                    {(state === 'ok' || state === 'near' || state === 'full') && <span>{shown}% used</span>}
                </div>
            </div>
        </div>
    );
}

/* ----- modules and add-ons ---------------------------------------------- */

function ModulesCard({ overview, plan }: { overview: SubscriptionOverview; plan: CataloguePlan | undefined }) {
    const inPlan = new Set([...(plan?.modules ?? []), ...(plan?.features ?? [])]);
    const included = (key: string) => !plan || inPlan.has(key);

    const items = [
        ...overview.modules.map((module) => ({
            key: `m:${module.key}`,
            name: module.name,
            on: module.enabled,
            note:
                module.source === 'off'
                    ? 'Not in plan'
                    : module.source === 'manual_revoke'
                        ? 'Switched off'
                        : module.source === 'addon'
                            ? 'Add-on'
                            : module.source === 'plan' || included(module.key)
                                ? 'Included'
                                : 'Enabled for you',
        })),
        ...overview.features.map((feature) => ({
            key: `f:${feature.key}`,
            name: feature.name,
            on: feature.enabled,
            note: !feature.enabled ? 'Not in plan' : included(feature.key) ? 'Included' : 'Enabled for you',
        })),
    ].sort((a, b) => Number(b.on) - Number(a.on));

    const active = items.filter((item) => item.on).length;
    const half = Math.ceil(items.length / 2);
    const allIncluded = items.every((item) => item.on && item.note === 'Included');

    return (
        <section className="sxp-card" aria-label="Included modules">
            <CardHead
                icon="ti ti-puzzle"
                title="Included modules"
                description={allIncluded ? 'All these modules are included in your current plan.' : 'What is switched on for your clinic, and where it comes from.'}
                aside={<span className="sxp-pill">{active} active</span>}
            />

            {items.length === 0 ? (
                <Empty icon="ti ti-puzzle" title="No modules yet" />
            ) : (
                <div className="sxp-mods">
                    {[items.slice(0, half), items.slice(half)].map((column, index) => (
                        <ul key={index}>
                            {column.map((item) => (
                                <li key={item.key} className={item.on ? '' : 'is-off'}>
                                    <i className={item.on ? 'ti ti-circle-check-filled' : 'ti ti-circle-dashed'} aria-hidden="true" />
                                    <span>{item.name}</span>
                                    <small>{item.note}</small>
                                </li>
                            ))}
                        </ul>
                    ))}
                </div>
            )}
        </section>
    );
}

function AddonsCard({
    overview,
    catalogue,
    labels,
    canAct,
    onAdd,
}: {
    overview: SubscriptionOverview;
    catalogue: CatalogueEntry | undefined;
    labels: Record<string, string>;
    canAct: boolean;
    onAdd: (preselect: Preselect) => void;
}) {
    const cycle: Cycle = overview.status.billing_cycle === 'annual' ? 'annual' : 'monthly';
    const unit = cycle === 'annual' ? 'year' : 'month';
    const enabled = new Set(overview.modules.filter((module) => module.enabled).map((module) => module.key));
    const owned = overview.addons.filter((addon) => addon.is_live);
    const tz = overview.status.timezone;

    const forSale = [
        ...(catalogue?.addons ?? []).map((addon) => ({
            id: `a:${addon.key}`,
            metric: metricOf(addon.target),
            name: addon.name,
            text: addon.description || `${labels[addon.target] ?? humanize(addon.target)} +${addon.unit_quantity.toLocaleString('en-IN')}`,
            price: cycle === 'annual' ? addon.annual_price : addon.monthly_price,
            oneTime: addon.billing === 'one_time',
            pick: { addon: addon.key } as Preselect,
        })),
        ...(catalogue?.modules ?? [])
            .filter((module) => !enabled.has(module.key))
            .map((module) => ({
                id: `m:${module.key}`,
                metric: { icon: 'ti ti-puzzle' } as { icon: string; hue?: string },
                name: `${module.name} module`,
                text: `Adds ${module.name} to your workspace.`,
                price: cycle === 'annual' ? module.annual_price : module.monthly_price,
                oneTime: false,
                pick: { module: module.key } as Preselect,
            })),
    ];

    return (
        <section className="sxp-card" aria-label="Available add-ons">
            <CardHead icon="ti ti-circle-plus" title="Available add-ons" description="Extend your plan with additional features and resources." />

            {owned.length > 0 && (
                <div className="sxp-owned">
                    <div className="sxp-group-head">
                        <h4>On your plan</h4>
                    </div>
                    <ul className="sxp-addons">
                        {owned.map((addon) => (
                            <li key={addon.id} className="sxp-addon is-owned">
                                <span className="sxp-addon-icon" aria-hidden="true">
                                    <i className="ti ti-circle-check" />
                                </span>
                                <div className="sxp-addon-body">
                                    <b>
                                        {addon.name}
                                        {addon.quantity > 1 ? ` × ${addon.quantity}` : ''}
                                    </b>
                                    <small>
                                        {addon.billing === 'one_time' ? `Until ${formatShortDay(addon.ends_at, tz)}` : 'Renews with your plan'}
                                    </small>
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {forSale.length > 0 ? (
                <>
                    {owned.length > 0 && (
                        <div className="sxp-group-head">
                            <h4>Available</h4>
                        </div>
                    )}
                    <ul className="sxp-addons">
                        {forSale.map((item) => (
                            <li key={item.id} className={`sxp-addon${item.metric.hue ? ` hue-${item.metric.hue}` : ''}`}>
                                <span className="sxp-addon-icon" aria-hidden="true">
                                    <i className={item.metric.icon} />
                                </span>
                                <div className="sxp-addon-body">
                                    <b>{item.name}</b>
                                    <small>{item.text}</small>
                                </div>
                                <div className="sxp-addon-price">
                                    {money(item.price)} <small>{item.oneTime ? 'once' : `/ ${unit}`}</small>
                                </div>
                                {canAct && (
                                    <button type="button" className="btn btn-sm sxp-btn-outline" onClick={() => onAdd(item.pick)}>
                                        {item.oneTime ? 'Buy' : 'Add'}
                                    </button>
                                )}
                            </li>
                        ))}
                    </ul>
                    <p className="sxp-card-note">
                        <i className="ti ti-info-circle" aria-hidden="true" />
                        Recurring add-ons are charged for the rest of this term, then renew with your plan.
                    </p>
                </>
            ) : (
                owned.length === 0 && (
                    <Empty icon="ti ti-package" title="No add-ons on sale right now">
                        <p>Need more branches, staff or messages? Our billing team can set it up for you.</p>
                        <a className="sxp-link" href={BILLING_MAIL}>
                            Contact billing support <i className="ti ti-arrow-right" aria-hidden="true" />
                        </a>
                    </Empty>
                )
            )}
        </section>
    );
}

/* ----- billing ----------------------------------------------------------- */

function kindLabel(kind: string): string {
    return (
        { new: 'New subscription', renewal: 'Renewal', upgrade: 'Upgrade', addon: 'Add-ons', overage: 'Usage over allowance', manual: 'Invoice' }[kind] ??
        humanize(kind)
    );
}

const INVOICE_STATUS: Record<string, { tone: string; icon: string; label: string }> = {
    paid: { tone: 'ok', icon: 'ti ti-circle-check-filled', label: 'Paid' },
    issued: { tone: 'warn', icon: 'ti ti-clock', label: 'Due' },
    void: { tone: 'muted', icon: 'ti ti-circle-x', label: 'Void' },
    draft: { tone: 'muted', icon: 'ti ti-pencil', label: 'Draft' },
};

function InvoiceStatus({ status }: { status: string }) {
    const view = INVOICE_STATUS[status] ?? { tone: 'muted', icon: 'ti ti-file', label: humanize(status) };

    return (
        <span className={`sx-chip sx-chip--${view.tone}`}>
            <i className={view.icon} aria-hidden="true" /> {view.label}
        </span>
    );
}

function invoicePeriod(invoice: SubscriptionInvoice, tz: string): string {
    if (invoice.period_start && invoice.period_end) {
        return `${formatShortDay(invoice.period_start, tz)} – ${formatShortDay(invoice.period_end, tz)}`;
    }

    return '—';
}

function BillingCard({
    invoices,
    loading,
    timezone,
    busy,
    onPay,
    onView,
    onPaymentMethods,
}: {
    invoices: SubscriptionInvoice[];
    loading: boolean;
    timezone: string;
    busy: boolean;
    onPay: (r: CheckoutResponse) => void;
    onView: (invoice: SubscriptionInvoice) => void;
    onPaymentMethods: () => void;
}) {
    const [all, setAll] = useState(false);
    const pay = usePayInvoice();
    const rows = all ? invoices : invoices.slice(0, 5);

    return (
        <section className="sxp-card" aria-label="Billing history">
            <CardHead
                icon="ti ti-receipt"
                title="Billing history"
                description="Your recent invoices and payments."
                aside={
                    invoices.length > 5 && (
                        <button type="button" className="btn btn-sm sxp-btn-outline" onClick={() => setAll((value) => !value)}>
                            {all ? 'Show recent' : 'View all invoices'} <i className={`ti ${all ? 'ti-chevron-up' : 'ti-arrow-right'} ms-1`} aria-hidden="true" />
                        </button>
                    )
                }
            />

            <div className="sxp-table-wrap">
                {loading ? (
                    <LoadingBlock />
                ) : rows.length === 0 ? (
                    <Empty icon="ti ti-receipt" title="No invoices yet">
                        <p>Invoices for renewals, upgrades and add-ons will be listed here.</p>
                    </Empty>
                ) : (
                    <table className="sxp-table">
                        <thead>
                            <tr>
                                <th>Invoice #</th>
                                <th>For</th>
                                <th>Billing period</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Payment date</th>
                                <th className="is-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((invoice) => (
                                <tr key={invoice.uuid}>
                                    <td className="is-strong">{invoice.number ?? '—'}</td>
                                    <td>{kindLabel(invoice.kind)}</td>
                                    <td>{invoicePeriod(invoice, timezone)}</td>
                                    <td className="is-strong is-num">{money(invoice.total, invoice.currency)}</td>
                                    <td>
                                        <InvoiceStatus status={invoice.status} />
                                    </td>
                                    <td>
                                        {invoice.paid_at ? (
                                            formatShortDay(invoice.paid_at, timezone)
                                        ) : invoice.status === 'issued' && invoice.due_at ? (
                                            <span className="is-due">Due {formatShortDay(invoice.due_at, timezone)}</span>
                                        ) : (
                                            '—'
                                        )}
                                    </td>
                                    <td className="is-end">
                                        <span className="sxp-row-actions">
                                            {invoice.status === 'issued' && (
                                                <Button
                                                    size="sm"
                                                    disabled={busy || pay.isPending}
                                                    onClick={async () => onPay(await pay.mutateAsync(invoice.uuid))}
                                                >
                                                    Pay now
                                                </Button>
                                            )}
                                            <button type="button" className="sxp-link" onClick={() => onView(invoice)}>
                                                <i className="ti ti-eye" aria-hidden="true" /> View
                                            </button>
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>

            <div className="sxp-card-foot">
                <button type="button" className="sxp-foot-link" onClick={onPaymentMethods}>
                    <i className="ti ti-credit-card" aria-hidden="true" /> Payment methods
                </button>
                <a className="sxp-foot-link" href={BILLING_MAIL}>
                    <i className="ti ti-headset" aria-hidden="true" /> Billing support
                </a>
                <span className="sxp-foot-note">Prices exclude tax unless stated on the invoice.</span>
            </div>
        </section>
    );
}

function DueAlert({
    invoice,
    timezone,
    busy,
    onPay,
}: {
    invoice: SubscriptionInvoice;
    timezone: string;
    busy: boolean;
    onPay: (r: CheckoutResponse) => void;
}) {
    const pay = usePayInvoice();

    return (
        <div className="sxp-card sxp-alert" role="status">
            <span className="sxp-alert-icon" aria-hidden="true">
                <i className="ti ti-file-invoice" />
            </span>
            <div className="sxp-alert-body">
                <b>Invoice {invoice.number} is waiting for payment</b>
                <small>
                    {kindLabel(invoice.kind)} · {money(invoice.total, invoice.currency)}
                    {invoice.due_at ? ` · due ${formatShortDay(invoice.due_at, timezone)}` : ''}
                </small>
            </div>
            <Button icon="ti ti-credit-card" disabled={busy || pay.isPending} onClick={async () => onPay(await pay.mutateAsync(invoice.uuid))}>
                Pay now
            </Button>
        </div>
    );
}

/* ----- dialogs ----------------------------------------------------------- */

function LimitList({ rows }: { rows: Array<[string, number | null]> }) {
    return (
        <dl className="sxp-limits">
            {rows.map(([label, value]) => (
                <div key={label}>
                    <dt>{label}</dt>
                    <dd>{value === null ? 'Unlimited' : value.toLocaleString('en-IN')}</dd>
                </div>
            ))}
        </dl>
    );
}

function PlanDetailsDialog({
    overview,
    plan,
    labels,
    canAct,
    onClose,
    onCompare,
}: {
    overview: SubscriptionOverview;
    plan: CataloguePlan | undefined;
    labels: Record<string, string>;
    canAct: boolean;
    onClose: () => void;
    onCompare: () => void;
}) {
    const status = overview.status;
    const limits: Record<string, number | null> = plan
        ? plan.limits
        : {
              ...Object.fromEntries(Object.values(overview.resources).map((row) => [row.key, row.unlimited ? null : row.limit])),
              ...Object.fromEntries(Object.values(overview.usage).map((row) => [row.key, row.unlimited ? null : row.included])),
          };
    const enabled = [...overview.modules, ...overview.features].filter((item) => item.enabled);

    return (
        <Modal
            open
            size="md"
            className="sxp-dialog"
            title={`${status.plan.name ?? 'Your'} plan`}
            subtitle={plan?.description ?? 'What your current plan includes'}
            icon={<i className="ti ti-crown" />}
            onClose={onClose}
            footer={
                <>
                    <Button variant="light" onClick={onClose}>
                        Close
                    </Button>
                    {canAct && (
                        <Button icon="ti ti-arrows-right-left" onClick={onCompare}>
                            Compare plans
                        </Button>
                    )}
                </>
            }
        >
            {!status.is_complimentary && (
                <div className="sxp-price-row">
                    <div>
                        <span>You pay</span>
                        <b>
                            {money(status.price, status.currency)} <small>/ {status.billing_cycle === 'annual' ? 'year' : 'month'}</small>
                        </b>
                    </div>
                    {plan && (
                        <div>
                            <span>{status.billing_cycle === 'annual' ? 'Monthly price' : 'Annual price'}</span>
                            <b>
                                {money(status.billing_cycle === 'annual' ? plan.monthly_price : plan.annual_price)}{' '}
                                <small>/ {status.billing_cycle === 'annual' ? 'month' : 'year'}</small>
                            </b>
                        </div>
                    )}
                </div>
            )}

            <h6 className="sxp-dialog-h">Limits</h6>
            <LimitList rows={limitRows(limits, labels)} />

            <h6 className="sxp-dialog-h">Modules & features</h6>
            <div className="sxp-tags">
                {enabled.map((item) => (
                    <span key={item.key} className="sxp-tag">
                        <i className="ti ti-check" aria-hidden="true" /> {item.name}
                    </span>
                ))}
            </div>

            {plan && plan.highlights.length > 0 && (
                <>
                    <h6 className="sxp-dialog-h mt-3">Highlights</h6>
                    <ul className="sxp-highlights">
                        {plan.highlights.map((line) => (
                            <li key={line}>
                                <i className="ti ti-sparkles" aria-hidden="true" /> {line}
                            </li>
                        ))}
                    </ul>
                </>
            )}
        </Modal>
    );
}

function PlanDialog({
    mode,
    status,
    catalogue,
    labels,
    busy,
    onClose,
    onCheckout,
}: {
    mode: PlanMode;
    status: SubscriptionStatus;
    catalogue: CatalogueEntry | undefined;
    labels: Record<string, string>;
    busy: boolean;
    onClose: () => void;
    onCheckout: (r: CheckoutResponse) => void;
}) {
    const renew = useRenew();
    const upgrade = useUpgrade();
    const [cycle, setCycle] = useState<Cycle>(status.billing_cycle === 'annual' ? 'annual' : 'monthly');
    const [planId, setPlanId] = useState<number | null>(null);
    const plans = catalogue?.plans ?? [];
    const shown = mode === 'upgrade' ? upgradeOptions(plans, status) : plans;
    const current = plans.find((plan) => plan.is_current);
    const chosen = shown.find((plan) => plan.id === planId) ?? (mode === 'upgrade' ? shown[0] : current ?? shown[0]);
    const goesUp = Boolean(chosen && !chosen.is_current && !status.locked && priceOf(chosen, cycle) > (status.price ?? 0));
    const mutation = goesUp ? upgrade : renew;
    const saving = Math.max(
        0,
        ...shown.map((plan) => (plan.monthly_price > 0 ? Math.round((1 - plan.annual_price / (plan.monthly_price * 12)) * 100) : 0)),
    );

    const cta = !chosen
        ? 'Continue'
        : chosen.is_current
            ? `Renew ${chosen.name}`
            : goesUp
                ? `Upgrade to ${chosen.name}`
                : `Switch to ${chosen.name} at renewal`;

    const title = mode === 'compare' ? 'Compare plans' : mode === 'renew' ? 'Renew subscription' : 'Upgrade plan';
    const subtitle =
        mode === 'compare'
            ? 'See what each plan includes, side by side'
            : mode === 'renew'
                ? 'Choose the plan for your next term'
                : 'Pay only the difference for the rest of this term';

    async function submit() {
        if (!chosen) {
            return;
        }

        try {
            onCheckout(await mutation.mutateAsync({ plan_id: chosen.id, billing_cycle: cycle }));
        } catch {
            // The interceptor has said why.
        }
    }

    const keyLimits = ['branches', 'doctors', 'employees', 'whatsapp_messages'];

    return (
        <Modal
            open
            size="lg"
            className="sxp-dialog"
            title={title}
            subtitle={subtitle}
            icon={<i className={mode === 'renew' ? 'ti ti-refresh' : mode === 'upgrade' ? 'ti ti-arrow-up' : 'ti ti-arrows-right-left'} />}
            onClose={onClose}
            busy={busy || mutation.isPending}
            footer={
                <>
                    <Button variant="light" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button disabled={!chosen || busy || mutation.isPending} loading={mutation.isPending} onClick={() => void submit()}>
                        {cta}
                    </Button>
                </>
            }
        >
            {!catalogue && <LoadingBlock />}

            {catalogue && shown.length === 0 ? (
                <Empty icon="ti ti-trophy" title="You're on the highest plan">
                    <p>There's no bigger plan to move to right now. Need more? Add-ons or our billing team can help.</p>
                    <a className="sxp-link" href={BILLING_MAIL}>
                        Contact billing support <i className="ti ti-arrow-right" aria-hidden="true" />
                    </a>
                </Empty>
            ) : (
                catalogue && (
                    <>
                        <div className="sxp-cycle" role="radiogroup" aria-label="Billing cycle">
                            {(['monthly', 'annual'] as const).map((value) => (
                                <button
                                    key={value}
                                    type="button"
                                    role="radio"
                                    aria-checked={cycle === value}
                                    className={cycle === value ? 'is-on' : ''}
                                    onClick={() => setCycle(value)}
                                >
                                    {value === 'monthly' ? 'Monthly' : 'Annual'}
                                    {value === 'annual' && saving > 0 && <em>Save up to {saving}%</em>}
                                </button>
                            ))}
                        </div>

                        <div className="sxp-plans" role="radiogroup" aria-label="Plans">
                            {shown.map((plan) => {
                                const selected = chosen?.id === plan.id;
                                const extraLimits = Object.keys(plan.limits).filter((key) => !keyLimits.includes(key)).length;

                                return (
                                    <button
                                        key={plan.id}
                                        type="button"
                                        role="radio"
                                        aria-checked={selected}
                                        className={`sxp-plan${selected ? ' is-on' : ''}`}
                                        onClick={() => setPlanId(plan.id)}
                                    >
                                        <span className="sxp-plan-top">
                                            <b>{plan.name}</b>
                                            {plan.is_current && <span className="sxp-pill">Current</span>}
                                        </span>
                                        <span className="sxp-plan-price">
                                            {money(priceOf(plan, cycle))} <small>/ {cycle === 'annual' ? 'year' : 'month'}</small>
                                        </span>
                                        {plan.description && <span className="sxp-plan-desc">{plan.description}</span>}
                                        <ul>
                                            {keyLimits
                                                .filter((key) => key in plan.limits)
                                                .map((key) => (
                                                    <li key={key}>
                                                        <i className="ti ti-check" aria-hidden="true" />
                                                        <span>
                                                            {plan.limits[key] === null ? 'Unlimited' : plan.limits[key]!.toLocaleString('en-IN')}{' '}
                                                            {(labels[key] ?? humanize(key)).toLowerCase().replace('whatsapp', 'WhatsApp')}
                                                        </span>
                                                    </li>
                                                ))}
                                            <li>
                                                <i className="ti ti-check" aria-hidden="true" />
                                                <span>
                                                    {plan.modules.length} modules
                                                    {plan.features.length > 0 ? ` + ${plan.features.length} features` : ''}
                                                </span>
                                            </li>
                                            {extraLimits > 0 && (
                                                <li>
                                                    <i className="ti ti-check" aria-hidden="true" />
                                                    <span>{extraLimits} more allowances</span>
                                                </li>
                                            )}
                                            {plan.highlights.slice(0, 3).map((line) => (
                                                <li key={line}>
                                                    <i className="ti ti-sparkles" aria-hidden="true" />
                                                    <span>{line}</span>
                                                </li>
                                            ))}
                                        </ul>
                                    </button>
                                );
                            })}
                        </div>

                        <p className="sxp-dialog-note">
                            <i className="ti ti-info-circle" aria-hidden="true" />
                            A bigger plan starts now and you pay the difference for this term. A smaller plan starts at your next renewal.
                            Prices exclude tax; the exact total is on the invoice before you pay.
                        </p>
                    </>
                )
            )}
        </Modal>
    );
}

function AddonsDialog({
    cycle,
    preselect,
    busy,
    onClose,
    onCheckout,
}: {
    cycle: string;
    preselect?: Preselect;
    busy: boolean;
    onClose: () => void;
    onCheckout: (r: CheckoutResponse) => void;
}) {
    const { data: catalogue, isLoading } = useSubscriptionCatalogue();
    const buy = useBuyAddons();
    const [quantities, setQuantities] = useState<Record<string, number>>(preselect?.addon ? { [preselect.addon]: 1 } : {});
    const [modules, setModules] = useState<string[]>(preselect?.module ? [preselect.module] : []);
    const [quote, setQuote] = useState<{ subtotal: number; tax: number; total: number } | null>(null);

    // The server prices it — a fresh quote whenever the choice changes.
    useEffect(() => {
        const addons = Object.fromEntries(Object.entries(quantities).filter(([, q]) => q > 0));

        if (Object.keys(addons).length === 0 && modules.length === 0) {
            setQuote(null);

            return;
        }

        let cancelled = false;
        quoteAddons({ addons, modules })
            .then((q) => !cancelled && setQuote(q))
            .catch(() => !cancelled && setQuote(null));

        return () => {
            cancelled = true;
        };
    }, [quantities, modules]);

    const price = (monthly: number, annual: number) => (cycle === 'annual' ? annual : monthly);

    async function submit() {
        try {
            onCheckout(await buy.mutateAsync({ addons: Object.fromEntries(Object.entries(quantities).filter(([, q]) => q > 0)), modules }));
        } catch {
            // The interceptor has said why.
        }
    }

    return (
        <Modal
            open
            size="lg"
            className="sxp-dialog"
            title="Buy add-ons"
            subtitle="Recurring add-ons are charged for the rest of this term, then renew with your plan"
            icon={<i className="ti ti-shopping-cart-plus" />}
            onClose={onClose}
            busy={busy || buy.isPending}
            footer={
                <>
                    <span className="me-auto sx-quote">
                        {quote ? (
                            <>
                                {formatPaise(quote.subtotal)} + tax {formatPaise(quote.tax)} = <b>{formatPaise(quote.total)}</b>
                            </>
                        ) : (
                            'Choose what to add'
                        )}
                    </span>
                    <Button variant="light" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button disabled={!quote || busy || buy.isPending} loading={buy.isPending} onClick={() => void submit()}>
                        Continue to payment
                    </Button>
                </>
            }
        >
            {isLoading && <LoadingBlock />}

            {catalogue && catalogue.addons.length === 0 && catalogue.modules.length === 0 && (
                <Empty icon="ti ti-package" title="Nothing is on sale as an add-on right now">
                    <a className="sxp-link" href={BILLING_MAIL}>
                        Contact billing support <i className="ti ti-arrow-right" aria-hidden="true" />
                    </a>
                </Empty>
            )}

            <ul className="sx-buy">
                {catalogue?.addons.map((addon) => (
                    <li key={addon.key}>
                        <div>
                            <b>{addon.name}</b>
                            <small>
                                {formatPaise(price(addon.monthly_price, addon.annual_price))}
                                {addon.billing === 'one_time' ? ' once' : ` / ${cycle === 'annual' ? 'year' : 'month'}`}
                                {addon.description ? ` · ${addon.description}` : ''}
                            </small>
                        </div>
                        <input
                            type="number"
                            min={0}
                            max={100}
                            className="form-control sx-qty"
                            aria-label={`How many: ${addon.name}`}
                            value={quantities[addon.key] ?? 0}
                            onChange={(e) => setQuantities({ ...quantities, [addon.key]: Math.max(0, Math.min(100, Number(e.target.value) || 0)) })}
                        />
                    </li>
                ))}
                {catalogue?.modules.map((module) => (
                    <li key={module.key}>
                        <div>
                            <b>{module.name} module</b>
                            <small>
                                {formatPaise(price(module.monthly_price, module.annual_price))} / {cycle === 'annual' ? 'year' : 'month'}
                            </small>
                        </div>
                        <input
                            type="checkbox"
                            className="form-check-input"
                            aria-label={`Add the ${module.name} module`}
                            checked={modules.includes(module.key)}
                            onChange={(e) => setModules(e.target.checked ? [...modules, module.key] : modules.filter((k) => k !== module.key))}
                        />
                    </li>
                ))}
            </ul>
        </Modal>
    );
}

function PaymentMethodsDialog({ online, onClose }: { online: boolean; onClose: () => void }) {
    return (
        <Modal
            open
            size="md"
            className="sxp-dialog"
            title="Payment methods"
            subtitle="How your clinic pays for its subscription"
            icon={<i className="ti ti-credit-card" />}
            onClose={onClose}
            footer={
                <>
                    <a className="btn btn-light" href={BILLING_MAIL}>
                        <i className="ti ti-headset me-1" aria-hidden="true" /> Billing support
                    </a>
                    <Button onClick={onClose}>Done</Button>
                </>
            }
        >
            <ul className="sxp-methods">
                <li className={online ? '' : 'is-off'}>
                    <span className="sxp-addon-icon" aria-hidden="true">
                        <i className="ti ti-device-mobile" />
                    </span>
                    <div>
                        <b>Pay online {online ? '' : '— not switched on yet'}</b>
                        <small>
                            UPI, debit and credit cards, net banking and wallets, through Razorpay. Your access updates the moment the payment
                            is confirmed.
                        </small>
                    </div>
                </li>
                <li>
                    <span className="sxp-addon-icon" aria-hidden="true">
                        <i className="ti ti-building-bank" />
                    </span>
                    <div>
                        <b>Bank transfer</b>
                        <small>Each invoice shows how to pay by transfer. Access updates once our team confirms the payment.</small>
                    </div>
                </li>
            </ul>
            <p className="sxp-dialog-note mb-0">
                <i className="ti ti-shield-check" aria-hidden="true" />
                Card and UPI details are entered on Razorpay's checkout and are never stored here.
            </p>
        </Modal>
    );
}

function InvoiceDialog({
    invoice,
    timezone,
    busy,
    onClose,
    onPay,
}: {
    invoice: SubscriptionInvoice;
    timezone: string;
    busy: boolean;
    onClose: () => void;
    onPay: (r: CheckoutResponse) => void;
}) {
    const pay = usePayInvoice();
    const payment = invoice.payments?.find((p) => p.status === 'captured');
    const rate = invoice.tax_rate_bp / 100;

    return (
        <Modal
            open
            size="md"
            className="sxp-dialog"
            title={`Invoice ${invoice.number ?? ''}`.trim()}
            subtitle={`${kindLabel(invoice.kind)}${invoice.issued_at ? ` · issued ${formatShortDay(invoice.issued_at, timezone)}` : ''}`}
            icon={<i className="ti ti-file-invoice" />}
            onClose={onClose}
            busy={busy || pay.isPending}
            footer={
                <>
                    <Button variant="light" onClick={onClose}>
                        Close
                    </Button>
                    {invoice.status === 'issued' && (
                        <Button
                            icon="ti ti-credit-card"
                            disabled={busy || pay.isPending}
                            onClick={async () => onPay(await pay.mutateAsync(invoice.uuid))}
                        >
                            Pay {money(invoice.total, invoice.currency)}
                        </Button>
                    )}
                </>
            }
        >
            <div className="sxp-invoice-meta">
                <InvoiceStatus status={invoice.status} />
                {invoice.period_start && invoice.period_end && <span>{invoicePeriod(invoice, timezone)}</span>}
            </div>

            <div className="sxp-table-wrap">
                <table className="sxp-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th className="is-end">Qty</th>
                            <th className="is-end">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        {invoice.lines.map((line, index) => (
                            <tr key={`${line.ref}-${index}`}>
                                <td className="sxp-wrap">{line.description}</td>
                                <td className="is-end is-num">{line.quantity}</td>
                                <td className="is-end is-num">{money(line.amount, invoice.currency)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <dl className="sxp-totals">
                <div>
                    <dt>Subtotal</dt>
                    <dd>{money(invoice.subtotal, invoice.currency)}</dd>
                </div>
                {invoice.tax > 0 && (
                    <div>
                        <dt>Tax{rate > 0 ? ` (${rate}%)` : ''}</dt>
                        <dd>{money(invoice.tax, invoice.currency)}</dd>
                    </div>
                )}
                <div className="is-total">
                    <dt>Total</dt>
                    <dd>{money(invoice.total, invoice.currency)}</dd>
                </div>
            </dl>

            {payment && (
                <p className="sxp-paid-note">
                    <i className="ti ti-circle-check-filled" aria-hidden="true" />
                    Paid {invoice.paid_at ? `on ${formatShortDay(invoice.paid_at, timezone)} ` : ''}
                    {payment.method === 'manual' ? 'by bank transfer' : 'online'}
                    {payment.reference ? ` · ref ${payment.reference}` : ''}
                </p>
            )}
        </Modal>
    );
}
