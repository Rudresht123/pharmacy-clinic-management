import { useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { createColumnHelper } from '@tanstack/react-table';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import { DataTable } from '@/shared/components/ui/DataTable';
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
    useSubscriptionUsage,
    useUpgrade,
    verifyPayment,
    type UsageRecordRow,
} from '../api';
import { openCheckout } from '../razorpay';
import { formatDay, formatPaise } from '../money';
import { SubscriptionStatusCard } from '../components/SubscriptionStatusCard';
import type { CheckoutResponse, QuotaUsage, ResourceUsage, SubscriptionInvoice } from '../types';

type Dialog = null | 'renew' | 'upgrade' | 'addons' | { offline: CheckoutResponse };

/**
 * The organization's own subscription: plan, dates, what it is using, what it
 * has bought, what it owes — and renewing, upgrading and buying add-ons.
 *
 * Every number on the page is from the server. Buying anything goes: server
 * quote → invoice → Razorpay checkout → the server verifies the payment →
 * the subscription changes. Paying here is the only way a clinic changes its
 * own subscription; plans, limits and dates are otherwise the platform's.
 */
export default function SubscriptionPage() {
    const { subscription: session, organization, refreshSession } = useTenantAuth();
    const { data, isLoading, isError, refetch } = useSubscription();
    const [params, setParams] = useSearchParams();
    const [dialog, setDialog] = useState<Dialog>(null);
    const [paying, setPaying] = useState(false);

    // ?action=renew|upgrade from the dashboard card and the banner.
    useEffect(() => {
        const action = params.get('action');

        if (action === 'renew' || action === 'upgrade' || action === 'addons') {
            setDialog(action);
            params.delete('action');
            setParams(params, { replace: true });
        }
    }, [params, setParams]);

    if (session && !session.can_manage) {
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
            setDialog({ offline: response });

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

    return (
        <>
            <PageHeader
                title="Subscription"
                subtitle="Your plan, usage, invoices and renewals"
                icon="ti ti-crown"
                actions={
                    data && !data.status.is_complimentary ? (
                        <div className="d-flex gap-2">
                            <Button variant="light" icon="ti ti-shopping-cart-plus" onClick={() => setDialog('addons')}>
                                Buy add-ons
                            </Button>
                            <Button icon="ti ti-refresh" onClick={() => setDialog('renew')}>
                                Renew
                            </Button>
                        </div>
                    ) : undefined
                }
            />

            {isLoading && <LoadingBlock />}
            {isError && <ErrorState onRetry={() => void refetch()} />}

            {data && (
                <div className="sx-page">
                    <SubscriptionStatusCard status={{ ...data.status, can_manage: true }} showActions={false} />

                    {data.open_invoices.length > 0 && (
                        <div className="sx-due">
                            {data.open_invoices.map((invoice) => (
                                <OpenInvoice key={invoice.uuid} invoice={invoice} busy={paying} onPaid={pay} />
                            ))}
                        </div>
                    )}

                    <div className="sx-grid">
                        <Card title="Capacity" icon="ti ti-building-hospital" className="sx-card">
                            {Object.values(data.resources).map((row) => (
                                <ResourceMeter key={row.key} row={row} />
                            ))}
                        </Card>

                        <Card title="This month's usage" icon="ti ti-chart-donut" className="sx-card">
                            {Object.values(data.usage).map((row) => (
                                <QuotaMeter key={row.key} row={row} />
                            ))}
                        </Card>
                    </div>

                    <div className="sx-grid">
                        <Card title="Modules" icon="ti ti-puzzle" className="sx-card">
                            <ul className="sx-list">
                                {data.modules.map((module) => (
                                    <li key={module.key} className={module.enabled ? '' : 'is-off'}>
                                        <i className={module.enabled ? 'ti ti-circle-check' : 'ti ti-circle-dashed'} aria-hidden="true" />
                                        <span>{module.name}</span>
                                        <small>{sourceLabel(module.source)}</small>
                                    </li>
                                ))}
                                {data.features.map((feature) => (
                                    <li key={feature.key} className={feature.enabled ? '' : 'is-off'}>
                                        <i className={feature.enabled ? 'ti ti-circle-check' : 'ti ti-circle-dashed'} aria-hidden="true" />
                                        <span>{feature.name}</span>
                                        <small>{feature.enabled ? 'Included' : 'Not in your plan'}</small>
                                    </li>
                                ))}
                            </ul>
                        </Card>

                        <Card title="Add-ons" icon="ti ti-plus" className="sx-card">
                            {data.addons.length === 0 ? (
                                <p className="sx-empty">No add-ons yet. Extra branches, staff, credit packs and features can be bought any time.</p>
                            ) : (
                                <ul className="sx-list">
                                    {data.addons.map((addon) => (
                                        <li key={addon.id} className={addon.is_live ? '' : 'is-off'}>
                                            <i className="ti ti-package" aria-hidden="true" />
                                            <span>
                                                {addon.name}
                                                {addon.quantity > 1 ? ` × ${addon.quantity}` : ''}
                                            </span>
                                            <small>
                                                {addon.is_live
                                                    ? addon.billing === 'one_time'
                                                        ? `Until ${formatDay(addon.ends_at, data.status.timezone)}`
                                                        : 'Renews with your plan'
                                                    : 'Ended'}
                                            </small>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Card>
                    </div>

                    <InvoicesCard timezone={data.status.timezone} busy={paying} onPay={pay} />
                    <UsageHistoryCard timezone={data.status.timezone} />
                </div>
            )}

            {data && dialog === 'renew' && (
                <PlanDialog mode="renew" currentCycle={data.status.billing_cycle} busy={paying} onClose={() => setDialog(null)} onCheckout={pay} />
            )}
            {data && dialog === 'upgrade' && (
                <PlanDialog mode="upgrade" currentCycle={data.status.billing_cycle} busy={paying} onClose={() => setDialog(null)} onCheckout={pay} />
            )}
            {data && dialog === 'addons' && (
                <AddonsDialog cycle={data.status.billing_cycle} busy={paying} onClose={() => setDialog(null)} onCheckout={pay} />
            )}
            {dialog && typeof dialog === 'object' && (
                <Modal open title="Invoice issued" icon={<i className="ti ti-file-invoice" />} onClose={() => setDialog(null)}
                    footer={<Button onClick={() => setDialog(null)}>Done</Button>}>
                    <p>
                        Invoice <strong>{dialog.offline.invoice.number}</strong> for{' '}
                        <strong>{formatPaise(dialog.offline.invoice.total, dialog.offline.invoice.currency)}</strong> is ready.
                    </p>
                    <p className="text-muted mb-0">{dialog.offline.offline}</p>
                </Modal>
            )}
        </>
    );
}

function sourceLabel(source: string): string {
    return (
        {
            plan: 'In your plan',
            addon: 'Add-on',
            manual_grant: 'Enabled for you',
            manual_revoke: 'Switched off',
            off: 'Not in your plan',
        }[source] ?? ''
    );
}

function Meter({ used, limit, unit }: { used: number; limit: number | null; unit?: string }) {
    if (limit === null) {
        return (
            <div className="sx-meter-figures">
                <b>{used.toLocaleString('en-IN')}</b> {unit} · <span className="text-muted">Unlimited</span>
            </div>
        );
    }

    const percent = limit === 0 ? (used > 0 ? 100 : 0) : Math.min(100, Math.round((used / limit) * 100));
    const tone = percent >= 100 ? 'is-full' : percent >= 80 ? 'is-near' : '';

    return (
        <>
            <div className="sx-meter-figures">
                <b>{used.toLocaleString('en-IN')}</b> / {limit.toLocaleString('en-IN')} {unit}
            </div>
            <div className={`sx-meter ${tone}`} role="progressbar" aria-valuenow={percent} aria-valuemin={0} aria-valuemax={100}>
                <span style={{ width: `${percent}%` }} />
            </div>
        </>
    );
}

function ResourceMeter({ row }: { row: ResourceUsage }) {
    return (
        <div className="sx-meter-row">
            <div className="sx-meter-label">
                {row.label}
                {row.over_limit && <span className="sx-chip sx-chip--locked ms-2">Over the limit</span>}
                {!row.over_limit && row.near_limit && <span className="sx-chip sx-chip--warn ms-2">Nearly full</span>}
            </div>
            <Meter used={row.used} limit={row.limit} />
        </div>
    );
}

function QuotaMeter({ row }: { row: QuotaUsage }) {
    const extra = row.purchased + row.granted;

    return (
        <div className="sx-meter-row">
            <div className="sx-meter-label">
                {row.label}
                {extra > 0 && <small className="text-muted ms-2">incl. {extra.toLocaleString('en-IN')} extra</small>}
            </div>
            <Meter used={row.used} limit={row.allowance} unit={row.unit} />
            {row.overage > 0 && (
                <small className="sx-overage">
                    {row.overage.toLocaleString('en-IN')} over the allowance · {formatPaise(row.overage_charge)} on next invoice
                </small>
            )}
        </div>
    );
}

function OpenInvoice({ invoice, busy, onPaid }: { invoice: SubscriptionInvoice; busy: boolean; onPaid: (r: CheckoutResponse) => void }) {
    const pay = usePayInvoice();

    return (
        <div className="sx-due-row">
            <i className="ti ti-file-invoice" aria-hidden="true" />
            <span>
                Invoice <strong>{invoice.number}</strong> — {formatPaise(invoice.total, invoice.currency)}, due{' '}
                {formatDay(invoice.due_at)}
            </span>
            <Button
                size="sm"
                disabled={busy || pay.isPending}
                onClick={async () => onPaid(await pay.mutateAsync(invoice.uuid))}
            >
                Pay now
            </Button>
        </div>
    );
}

const invoiceColumn = createColumnHelper<SubscriptionInvoice>();

function InvoicesCard({ timezone, busy, onPay }: { timezone: string; busy: boolean; onPay: (r: CheckoutResponse) => void }) {
    const { data, isLoading, isError, refetch } = useSubscriptionInvoices();
    const pay = usePayInvoice();

    const columns = useMemo(
        () => [
            invoiceColumn.accessor('number', { header: 'Invoice', cell: (info) => <b>{info.getValue() ?? '—'}</b> }),
            invoiceColumn.accessor('kind', { header: 'For', cell: (info) => kindLabel(info.getValue()) }),
            invoiceColumn.accessor('issued_at', { header: 'Issued', cell: (info) => formatDay(info.getValue(), timezone) }),
            invoiceColumn.accessor('total', { header: 'Total', cell: (info) => formatPaise(info.getValue(), info.row.original.currency) }),
            invoiceColumn.accessor('status', {
                header: 'Status',
                cell: (info) => <span className={`sx-chip sx-chip--${info.getValue() === 'paid' ? 'ok' : info.getValue() === 'void' ? 'muted' : 'warn'}`}>{info.getValue()}</span>,
            }),
            invoiceColumn.display({
                id: 'paid',
                header: 'Paid',
                cell: (info) => {
                    const invoice = info.row.original;

                    if (invoice.status === 'issued') {
                        return (
                            <Button size="sm" variant="light" disabled={busy || pay.isPending} onClick={async () => onPay(await pay.mutateAsync(invoice.uuid))}>
                                Pay
                            </Button>
                        );
                    }

                    const payment = invoice.payments?.find((p) => p.status === 'captured');

                    return payment ? `${formatDay(invoice.paid_at, timezone)} · ${payment.method === 'manual' ? 'Bank transfer' : 'Online'}` : '—';
                },
            }),
        ],
        [timezone, busy, onPay, pay],
    );

    return (
        <Card title="Invoices & payments" icon="ti ti-receipt" className="sx-card">
            <DataTable
                data={data ?? []}
                columns={columns}
                loading={isLoading}
                error={isError}
                onRetry={() => void refetch()}
                hideSearch
                emptyIcon="ti ti-receipt"
                emptyTitle="No invoices yet"
                emptyDescription="Invoices for renewals, upgrades and add-ons appear here."
            />
        </Card>
    );
}

function kindLabel(kind: string): string {
    return (
        { new: 'New subscription', renewal: 'Renewal', upgrade: 'Upgrade', addon: 'Add-ons', overage: 'Usage over allowance', manual: 'Invoice' }[kind] ??
        kind
    );
}

const usageColumn = createColumnHelper<UsageRecordRow>();

function UsageHistoryCard({ timezone }: { timezone: string }) {
    const [page, setPage] = useState(1);
    const { data, isLoading, isError, refetch } = useSubscriptionUsage(page);

    const columns = useMemo(
        () => [
            usageColumn.accessor('occurred_at', { header: 'When', cell: (info) => formatDay(info.getValue(), timezone) }),
            usageColumn.accessor('channel', { header: 'Channel', cell: (info) => ({ whatsapp: 'WhatsApp', email: 'Email', sms: 'SMS', video: 'Video consultation' }[info.getValue()] ?? info.getValue()) }),
            usageColumn.accessor('quantity', { header: 'Used', cell: (info) => `${info.getValue()} ${info.row.original.unit}${info.getValue() === 1 ? '' : 's'}` }),
            usageColumn.accessor('from_quota', { header: 'From allowance' }),
            usageColumn.accessor('charge', { header: 'Charged', cell: (info) => (info.getValue() > 0 ? formatPaise(info.getValue()) : '—') }),
            usageColumn.accessor('status', { header: '', cell: (info) => (info.getValue() === 'reversed' ? <span className="sx-chip sx-chip--muted">Refunded</span> : null) }),
        ],
        [timezone],
    );

    return (
        <Card title="Usage history" icon="ti ti-list-details" className="sx-card">
            <DataTable
                data={data?.records ?? []}
                columns={columns}
                loading={isLoading}
                error={isError}
                onRetry={() => void refetch()}
                hideSearch
                emptyIcon="ti ti-message"
                emptyTitle="No usage yet"
                emptyDescription="Each WhatsApp message, email and video consultation appears here once it goes out."
            />
            {(data?.meta?.last_page ?? 1) > 1 && (
                <div className="d-flex justify-content-end gap-2 mt-2">
                    <Button size="sm" variant="light" disabled={page <= 1} onClick={() => setPage(page - 1)}>Previous</Button>
                    <Button size="sm" variant="light" disabled={page >= (data?.meta?.last_page ?? 1)} onClick={() => setPage(page + 1)}>Next</Button>
                </div>
            )}
        </Card>
    );
}

function PlanDialog({
    mode,
    currentCycle,
    busy,
    onClose,
    onCheckout,
}: {
    mode: 'renew' | 'upgrade';
    currentCycle: string;
    busy: boolean;
    onClose: () => void;
    onCheckout: (r: CheckoutResponse) => void;
}) {
    const { data: catalogue, isLoading } = useSubscriptionCatalogue();
    const renew = useRenew();
    const upgrade = useUpgrade();
    const current = catalogue?.plans.find((plan) => plan.is_current);
    const [planId, setPlanId] = useState<number | null>(null);
    const [cycle, setCycle] = useState<'monthly' | 'annual'>(currentCycle === 'annual' ? 'annual' : 'monthly');
    const chosen = catalogue?.plans.find((plan) => plan.id === (planId ?? current?.id));
    const mutation = mode === 'renew' ? renew : upgrade;

    async function submit() {
        if (!chosen) {
            return;
        }

        try {
            const response = await mutation.mutateAsync({ plan_id: chosen.id, billing_cycle: cycle });
            onCheckout(response);
        } catch {
            // The interceptor has said why.
        }
    }

    return (
        <Modal
            open
            size="lg"
            title={mode === 'renew' ? 'Renew subscription' : 'Upgrade plan'}
            subtitle={mode === 'renew' ? 'Choose the plan for your next term' : 'Pay only the difference for the rest of this term'}
            icon={<i className={mode === 'renew' ? 'ti ti-refresh' : 'ti ti-arrow-up-right'} />}
            onClose={onClose}
            busy={busy || mutation.isPending}
            footer={
                <>
                    <Button variant="light" onClick={onClose}>Cancel</Button>
                    <Button disabled={!chosen || busy || mutation.isPending} loading={mutation.isPending} onClick={() => void submit()}>
                        Continue to payment
                    </Button>
                </>
            }
        >
            {isLoading && <LoadingBlock />}

            <div className="sx-cycle" role="radiogroup" aria-label="Billing">
                {(['monthly', 'annual'] as const).map((value) => (
                    <button key={value} type="button" role="radio" aria-checked={cycle === value} className={cycle === value ? 'is-on' : ''} onClick={() => setCycle(value)}>
                        {value === 'monthly' ? 'Monthly' : 'Annual'}
                    </button>
                ))}
            </div>

            <div className="sx-plans">
                {catalogue?.plans
                    .filter((plan) => mode === 'renew' || !plan.is_current)
                    .map((plan) => {
                        const price = cycle === 'annual' ? plan.annual_price : plan.monthly_price;
                        const selected = (planId ?? current?.id) === plan.id;

                        return (
                            <button key={plan.id} type="button" className={`sx-plan ${selected ? 'is-on' : ''}`} onClick={() => setPlanId(plan.id)}>
                                <span className="sx-plan-name">
                                    {plan.name}
                                    {plan.is_current && <small> · current</small>}
                                </span>
                                <span className="sx-plan-price">
                                    {formatPaise(price)} <small>/ {cycle === 'annual' ? 'year' : 'month'}</small>
                                </span>
                                {plan.highlights.length > 0 && (
                                    <ul>
                                        {plan.highlights.slice(0, 4).map((line) => (
                                            <li key={line}>{line}</li>
                                        ))}
                                    </ul>
                                )}
                            </button>
                        );
                    })}
            </div>

            <p className="text-muted small mb-0">Prices exclude tax unless stated. The exact total, with tax, is on the invoice before you pay.</p>
        </Modal>
    );
}

function AddonsDialog({
    cycle,
    busy,
    onClose,
    onCheckout,
}: {
    cycle: string;
    busy: boolean;
    onClose: () => void;
    onCheckout: (r: CheckoutResponse) => void;
}) {
    const { data: catalogue, isLoading } = useSubscriptionCatalogue();
    const buy = useBuyAddons();
    const [quantities, setQuantities] = useState<Record<string, number>>({});
    const [modules, setModules] = useState<string[]>([]);
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
                    <Button variant="light" onClick={onClose}>Cancel</Button>
                    <Button disabled={!quote || busy || buy.isPending} loading={buy.isPending} onClick={() => void submit()}>
                        Continue to payment
                    </Button>
                </>
            }
        >
            {isLoading && <LoadingBlock />}

            {catalogue && catalogue.addons.length === 0 && catalogue.modules.length === 0 && (
                <p className="sx-empty">Nothing is on sale as an add-on right now.</p>
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
