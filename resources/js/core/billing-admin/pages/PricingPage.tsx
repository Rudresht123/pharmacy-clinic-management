import { useEffect, useMemo, useState } from 'react';
import { createColumnHelper } from '@tanstack/react-table';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import { DataTable } from '@/shared/components/ui/DataTable';
import { DonutChart } from '@/shared/components/ui/DonutChart';
import { Tabs } from '@/shared/components/ui/Tabs';
import { formatPaise, paiseToRupees, rupeesToPaise } from '@/core/subscription/money';
import {
    useAddons,
    useBillingMutation,
    useModulePrices,
    usePlans,
    usePricingInsights,
    useUsageRates,
    type Addon,
    type ModulePriceRow,
    type UsageRate,
} from '../api';
import { FEATURE_KEYS, LABELS, QUOTA_KEYS, RESOURCE_KEYS, formatE4, rupeesToE4 } from '../labels';
import { ActionDialog, FieldError, StatusChip } from '../components/Common';
import { ChartCard, compactPaise, GroupedBars } from '../components/Visuals';

type Cycle = 'monthly' | 'annual';
type Tab = 'addons' | 'rates';

const TREND = [
    { label: 'Revenue', color: 'rgb(var(--brand))' },
    { label: 'Projected (renewals due)', color: 'rgba(var(--brand), 0.35)' },
];

const whole = (paise: number) => formatPaise(paise).replace(/\.00$/, '');

/**
 * Module-wise pricing against the plans that include each module, the money
 * it has brought in, and — below — add-ons and the per-unit communication and
 * video rates (with their provider cost, so the margin is visible here and
 * nowhere public).
 */
export default function PricingPage() {
    const [cycle, setCycle] = useState<Cycle>('monthly');
    const [tab, setTab] = useState<Tab>('addons');
    const [newAddon, setNewAddon] = useState(false);
    const { data: insights } = usePricingInsights();
    const { data: plans } = usePlans();

    // "Save up to …" from the published plans' own prices, not a slogan.
    const saving = Math.max(
        0,
        ...(plans ?? [])
            .filter((p) => p.status === 'published' && !p.is_system && p.monthly_price > 0)
            .map((p) => Math.round((1 - p.annual_price / (p.monthly_price * 12)) * 100)),
    );

    return (
        <>
            <PageHeader
                title="Pricing"
                subtitle="Manage module-wise pricing and configure rates for plans."
                icon="ti ti-tag"
                actions={
                    <div className="d-flex flex-wrap gap-2 align-items-center">
                        <div className="sx-cycle mb-0" role="radiogroup" aria-label="Billing">
                            <button type="button" role="radio" aria-checked={cycle === 'monthly'} className={cycle === 'monthly' ? 'is-on' : ''} onClick={() => setCycle('monthly')}>
                                Monthly
                            </button>
                            <button type="button" role="radio" aria-checked={cycle === 'annual'} className={cycle === 'annual' ? 'is-on' : ''} onClick={() => setCycle('annual')}>
                                Yearly{saving > 0 && <small className="sxa-save"> Save up to {saving}%</small>}
                            </button>
                        </div>
                        <Button icon="ti ti-plus" onClick={() => { setTab('addons'); setNewAddon(true); }}>
                            Add Add-on
                        </Button>
                    </div>
                }
            />

            <div className="sxa-split">
                <ChartCard title="Pricing Overview" legend={TREND}>
                    <GroupedBars points={insights?.trend ?? []} series={TREND} format={(v) => formatPaise(v)} axis={compactPaise} empty="No revenue or renewals due in these months." />
                </ChartCard>
                <ChartCard title="Revenue by Module">
                    <DonutChart slices={insights?.revenue_by_module ?? []} centreLabel="Last 12 months" format={compactPaise} empty="No paid invoices yet." />
                </ChartCard>
            </div>

            <ModuleTable cycle={cycle} />

            <div className="mt-4">
                <Tabs
                    label="More pricing"
                    value={tab}
                    onChange={setTab}
                    tabs={[
                        { value: 'addons', label: 'Add-ons', icon: 'ti ti-plus' },
                        { value: 'rates', label: 'Usage rates', icon: 'ti ti-message-dollar' },
                    ]}
                />
                <div className="mt-3">
                    {tab === 'addons' && <Addons creating={newAddon} onCreated={() => setNewAddon(false)} />}
                    {tab === 'rates' && <Rates />}
                </div>
            </div>
        </>
    );
}

/** One row per module; one column per plan on sale (Included, or the add-on price). */
function ModuleTable({ cycle }: { cycle: Cycle }) {
    const { data, isLoading } = useModulePrices();
    const { data: plans } = usePlans();
    const [editing, setEditing] = useState<ModulePriceRow | null>(null);
    const onSale = (plans ?? []).filter((p) => p.status === 'published' && !p.is_system).slice(0, 4);
    const price = (row: ModulePriceRow) => (row.current ? (cycle === 'annual' ? row.current.annual_price : row.current.monthly_price) : null);

    return (
        <Card>
            <div className="sxa-chart-head">
                <h6>Module Pricing</h6>
                <span className="text-muted small">
                    {cycle === 'annual' ? 'Per year' : 'Per month'} · add-on price where a plan does not include the module
                </span>
            </div>
            <div className="sxa-table">
                <table className="table mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Module</th>
                            <th>Description</th>
                            {onSale.map((plan) => (
                                <th key={plan.id}>{plan.name}</th>
                            ))}
                            <th>Add-on</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        {(data ?? []).map((row, i) => {
                            const addon = price(row);

                            return (
                                <tr key={row.module.key}>
                                    <td>{i + 1}</td>
                                    <td>
                                        <b>{row.module.name}</b>
                                    </td>
                                    <td className="text-muted">{row.module.description}</td>
                                    {onSale.map((plan) => (
                                        <td key={plan.id}>
                                            {plan.modules.includes(row.module.key) ? (
                                                <span className="sx-chip sx-chip--ok">Included</span>
                                            ) : addon !== null && row.current?.is_addon_eligible ? (
                                                whole(addon)
                                            ) : (
                                                <span className="text-muted">—</span>
                                            )}
                                        </td>
                                    ))}
                                    <td>{addon === null ? <span className="text-muted">Not priced</span> : whole(addon)}</td>
                                    <td>
                                        <StatusChip
                                            status={row.module.is_active && row.current ? 'published' : 'draft'}
                                            label={!row.module.is_active ? 'Retired' : row.current ? 'Active' : 'No price'}
                                        />
                                    </td>
                                    <td>
                                        <Button size="sm" variant="light" icon="ti ti-pencil" onClick={() => setEditing(row)}>
                                            Edit
                                        </Button>
                                    </td>
                                </tr>
                            );
                        })}
                        {!isLoading && (data ?? []).length === 0 && (
                            <tr>
                                <td colSpan={6 + onSale.length} className="text-center text-muted py-4">
                                    No modules.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
            {editing && <ModulePriceDialog row={editing} onClose={() => setEditing(null)} />}
        </Card>
    );
}

function ModulePriceDialog({ row, onClose }: { row: ModulePriceRow; onClose: () => void }) {
    const save = useBillingMutation<Record<string, unknown>>('post', `/module-prices/${row.module.id}`);
    const [form, setForm] = useState({
        monthly: paiseToRupees(row.current?.monthly_price ?? 0),
        annual: paiseToRupees(row.current?.annual_price ?? 0),
        cost: paiseToRupees(row.current?.provider_cost ?? null),
        addon: row.current?.is_addon_eligible ?? true,
        trial: row.current?.trial_available ?? false,
        public: row.current?.is_public ?? true,
        from: '',
    });

    return (
        <ActionDialog
            title={`Price for ${row.module.name}`}
            subtitle="A new price takes over from its date; the old one stays in the history."
            icon="ti ti-tag"
            askReason={false}
            submitLabel="Save price"
            onClose={onClose}
            submit={() =>
                save.mutateAsync({
                    monthly_price: rupeesToPaise(form.monthly) ?? -1,
                    annual_price: rupeesToPaise(form.annual) ?? -1,
                    provider_cost: form.cost.trim() === '' ? null : rupeesToPaise(form.cost),
                    is_addon_eligible: form.addon,
                    trial_available: form.trial,
                    is_public: form.public,
                    effective_from: form.from || null,
                })
            }
        >
            {(errors) => (
                <>
                    <div className="row g-3 mb-3">
                        <div className="col-md-4">
                            <label className="form-label">Monthly (₹)</label>
                            <input className="form-control" value={form.monthly} onChange={(e) => setForm({ ...form, monthly: e.target.value })} />
                            <FieldError errors={errors} name="monthly_price" />
                        </div>
                        <div className="col-md-4">
                            <label className="form-label">Annual (₹)</label>
                            <input className="form-control" value={form.annual} onChange={(e) => setForm({ ...form, annual: e.target.value })} />
                            <FieldError errors={errors} name="annual_price" />
                        </div>
                        <div className="col-md-4">
                            <label className="form-label">Cost to us (₹/month)</label>
                            <input className="form-control" value={form.cost} placeholder="Optional" onChange={(e) => setForm({ ...form, cost: e.target.value })} />
                        </div>
                        <div className="col-md-6">
                            <label className="form-label">Takes effect</label>
                            <input type="datetime-local" className="form-control" value={form.from} onChange={(e) => setForm({ ...form, from: e.target.value })} />
                            <small className="text-muted">Blank: now.</small>
                        </div>
                    </div>
                    <div className="sxa-checks mb-3">
                        <label><input type="checkbox" className="form-check-input" checked={form.addon} onChange={(e) => setForm({ ...form, addon: e.target.checked })} /> Can be bought as an add-on</label>
                        <label><input type="checkbox" className="form-check-input" checked={form.public} onChange={(e) => setForm({ ...form, public: e.target.checked })} /> Show the price on the website</label>
                        <label><input type="checkbox" className="form-check-input" checked={form.trial} onChange={(e) => setForm({ ...form, trial: e.target.checked })} /> Can be trialled</label>
                    </div>
                    {row.history.length > 1 && (
                        <>
                            <div className="sxa-section-title">History</div>
                            <ul className="sxa-events">
                                {row.history.map((price) => (
                                    <li key={price.id}>
                                        <b>{formatPaise(price.monthly_price)}</b> / month · {formatPaise(price.annual_price)} / year
                                        <small>
                                            From {new Date(price.effective_from).toLocaleDateString('en-IN')}
                                            {price.effective_to ? ` to ${new Date(price.effective_to).toLocaleDateString('en-IN')}` : ' — current'}
                                        </small>
                                    </li>
                                ))}
                            </ul>
                        </>
                    )}
                </>
            )}
        </ActionDialog>
    );
}

/* --- add-ons --------------------------------------------------------------- */

const addonColumn = createColumnHelper<Addon>();

function Addons({ creating, onCreated }: { creating: boolean; onCreated: () => void }) {
    const { data, isLoading, isError, refetch } = useAddons();
    const [editing, setEditing] = useState<Addon | 'new' | null>(null);

    // The header's "Add Add-on" opens the same dialog.
    useEffect(() => {
        if (creating) {
            setEditing('new');
            onCreated();
        }
    }, [creating, onCreated]);
    const status = useBillingMutation<{ key: string; status: string }>('post', (b) => `/addons/${b.key}/status`);

    const columns = useMemo(
        () => [
            addonColumn.accessor('name', { header: 'Add-on', cell: (info) => <div><b>{info.getValue()}</b><div className="dt-muted">{info.row.original.key}</div></div> }),
            addonColumn.accessor('target_label', { header: 'Grants', cell: (info) => `${info.row.original.kind === 'feature' ? '' : `+${info.row.original.unit_quantity} `}${info.getValue()}` }),
            addonColumn.accessor('billing', { header: 'Billing', cell: (info) => (info.getValue() === 'one_time' ? 'Once (this month)' : 'Recurring') }),
            addonColumn.accessor('monthly_price', { header: 'Monthly / once', cell: (info) => formatPaise(info.getValue()) }),
            addonColumn.accessor('annual_price', { header: 'Annual', cell: (info) => (info.row.original.billing === 'one_time' ? '—' : formatPaise(info.getValue())) }),
            addonColumn.accessor('status', { header: 'Status', cell: (info) => <StatusChip status={info.getValue()} /> }),
            addonColumn.display({
                id: 'actions',
                header: '',
                cell: (info) => {
                    const addon = info.row.original;

                    return (
                        <div className="sxa-row-actions">
                            <Button size="sm" variant="light" icon="ti ti-pencil" onClick={() => setEditing(addon)}>Edit</Button>
                            {addon.status !== 'published' && <Button size="sm" onClick={() => status.mutate({ key: addon.key, status: 'published' })}>Publish</Button>}
                            {addon.status === 'published' && <Button size="sm" variant="light" onClick={() => status.mutate({ key: addon.key, status: 'unpublished' })}>Unpublish</Button>}
                        </div>
                    );
                },
            }),
        ],
        [status],
    );

    return (
        <Card actions={<Button size="sm" icon="ti ti-plus" onClick={() => setEditing('new')}>New add-on</Button>}>
            <DataTable
                data={data ?? []}
                columns={columns}
                loading={isLoading}
                error={isError}
                onRetry={() => void refetch()}
                hideSearch
                emptyIcon="ti ti-plus"
                emptyTitle="No add-ons yet"
                emptyDescription="Extra branches, doctors or staff, credit packs, and features like video consultation."
            />
            {editing && <AddonDialog addon={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
        </Card>
    );
}

function AddonDialog({ addon, onClose }: { addon: Addon | null; onClose: () => void }) {
    const save = useBillingMutation<Record<string, unknown>>(addon ? 'put' : 'post', addon ? `/addons/${addon.key}` : '/addons');
    const [form, setForm] = useState({
        key: addon?.key ?? '',
        name: addon?.name ?? '',
        description: addon?.description ?? '',
        kind: addon?.kind ?? 'resource',
        target: addon?.target ?? 'doctors',
        unit_quantity: addon?.unit_quantity ?? 1,
        billing: addon?.billing ?? 'recurring',
        monthly: paiseToRupees(addon?.monthly_price ?? 0),
        annual: paiseToRupees(addon?.annual_price ?? 0),
        cost: paiseToRupees(addon?.provider_cost ?? null),
        is_public: addon?.is_public ?? true,
    });

    const targets = form.kind === 'resource' ? RESOURCE_KEYS : form.kind === 'credits' ? QUOTA_KEYS : FEATURE_KEYS;

    return (
        <ActionDialog
            title={addon ? `Edit ${addon.name}` : 'New add-on'}
            icon="ti ti-plus"
            askReason={false}
            submitLabel="Save add-on"
            onClose={onClose}
            submit={() =>
                save.mutateAsync({
                    ...(addon === null || addon.status === 'draft' ? { key: form.key } : {}),
                    name: form.name,
                    description: form.description || null,
                    kind: form.kind,
                    target: form.target,
                    unit_quantity: Number(form.unit_quantity) || 1,
                    billing: form.billing,
                    monthly_price: rupeesToPaise(form.monthly) ?? -1,
                    annual_price: rupeesToPaise(form.annual) ?? -1,
                    provider_cost: form.cost.trim() === '' ? null : rupeesToPaise(form.cost),
                    is_public: form.is_public,
                })
            }
        >
            {(errors) => (
                <div className="row g-3 mb-2">
                    <div className="col-md-6">
                        <label className="form-label">Name *</label>
                        <input className="form-control" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
                        <FieldError errors={errors} name="name" />
                    </div>
                    <div className="col-md-6">
                        <label className="form-label">Key *</label>
                        <input className="form-control" value={form.key} disabled={Boolean(addon && addon.status !== 'draft')} placeholder="extra_doctor" onChange={(e) => setForm({ ...form, key: e.target.value })} />
                        <FieldError errors={errors} name="key" />
                    </div>
                    <div className="col-md-4">
                        <label className="form-label">Kind</label>
                        <select className="form-select" value={form.kind} onChange={(e) => {
                            const kind = e.target.value as Addon['kind'];
                            setForm({ ...form, kind, target: (kind === 'resource' ? RESOURCE_KEYS : kind === 'credits' ? QUOTA_KEYS : FEATURE_KEYS)[0] });
                        }}>
                            <option value="resource">Extra capacity</option>
                            <option value="credits">Credit pack</option>
                            <option value="feature">Feature</option>
                        </select>
                    </div>
                    <div className="col-md-4">
                        <label className="form-label">Grants</label>
                        <select className="form-select" value={form.target} onChange={(e) => setForm({ ...form, target: e.target.value })}>
                            {targets.map((key) => <option key={key} value={key}>{LABELS[key]}</option>)}
                        </select>
                        <FieldError errors={errors} name="target" />
                    </div>
                    {form.kind !== 'feature' && (
                        <div className="col-md-4">
                            <label className="form-label">Units per add-on</label>
                            <input type="number" min={1} className="form-control" value={form.unit_quantity} onChange={(e) => setForm({ ...form, unit_quantity: Number(e.target.value) })} />
                        </div>
                    )}
                    <div className="col-md-4">
                        <label className="form-label">Billing</label>
                        <select className="form-select" value={form.billing} onChange={(e) => setForm({ ...form, billing: e.target.value as Addon['billing'] })}>
                            <option value="recurring">Recurring (with the plan)</option>
                            <option value="one_time">Once (this month)</option>
                        </select>
                    </div>
                    <div className="col-md-4">
                        <label className="form-label">{form.billing === 'one_time' ? 'Price (₹)' : 'Monthly (₹)'}</label>
                        <input className="form-control" value={form.monthly} onChange={(e) => setForm({ ...form, monthly: e.target.value })} />
                        <FieldError errors={errors} name="monthly_price" />
                    </div>
                    {form.billing === 'recurring' && (
                        <div className="col-md-4">
                            <label className="form-label">Annual (₹)</label>
                            <input className="form-control" value={form.annual} onChange={(e) => setForm({ ...form, annual: e.target.value })} />
                        </div>
                    )}
                    <div className="col-md-4">
                        <label className="form-label">Cost to us (₹)</label>
                        <input className="form-control" value={form.cost} placeholder="Optional" onChange={(e) => setForm({ ...form, cost: e.target.value })} />
                    </div>
                    <div className="col-12">
                        <label className="form-label">Description</label>
                        <textarea className="form-control" rows={2} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
                    </div>
                    <div className="col-12 sxa-checks">
                        <label><input type="checkbox" className="form-check-input" checked={form.is_public} onChange={(e) => setForm({ ...form, is_public: e.target.checked })} /> Show on the website</label>
                    </div>
                </div>
            )}
        </ActionDialog>
    );
}

/* --- usage rates --------------------------------------------------------- */

const rateColumn = createColumnHelper<UsageRate>();

function Rates() {
    const { data, isLoading, isError, refetch } = useUsageRates();
    const [creating, setCreating] = useState(false);
    const publish = useBillingMutation<{ id: number }>('post', (b) => `/usage-rates/${b.id}/publish`);
    const archive = useBillingMutation<{ id: number }>('post', (b) => `/usage-rates/${b.id}/archive`);

    const columns = useMemo(
        () => [
            rateColumn.accessor('channel', {
                header: 'Channel',
                cell: (info) => (
                    <div>
                        <b>{{ whatsapp: 'WhatsApp', sms: 'SMS', email: 'Email', video: 'Video' }[info.getValue()]}</b>
                        <div className="dt-muted">
                            {info.row.original.provider === '*' ? 'Any provider' : info.row.original.provider}
                            {info.row.original.category ? ` · ${info.row.original.category}` : ''}
                            {info.row.original.destination !== '*' ? ` · ${info.row.original.destination}` : ''}
                        </div>
                    </div>
                ),
            }),
            rateColumn.accessor('provider_cost_e4', { header: 'Cost to us', cell: (info) => `${formatE4(info.getValue())} / ${info.row.original.billing_unit}` }),
            rateColumn.accessor('price_e4', { header: 'Sold at', cell: (info) => `${formatE4(info.getValue())} / ${info.row.original.billing_unit}` }),
            rateColumn.accessor('margin_e4', { header: 'Margin', cell: (info) => formatE4(info.getValue()) }),
            rateColumn.accessor('overage_policy', { header: 'Past allowance', cell: (info) => (info.getValue() === 'allow' ? 'Charge overage' : 'Block sending') }),
            rateColumn.accessor('effective_from', {
                header: 'In force',
                cell: (info) => `${new Date(info.getValue()).toLocaleDateString('en-IN')}${info.row.original.effective_to ? ` – ${new Date(info.row.original.effective_to).toLocaleDateString('en-IN')}` : ' →'}`,
            }),
            rateColumn.accessor('status', { header: 'Status', cell: (info) => <StatusChip status={info.getValue()} /> }),
            rateColumn.display({
                id: 'actions',
                header: '',
                cell: (info) => {
                    const rate = info.row.original;

                    return (
                        <div className="sxa-row-actions">
                            {rate.status === 'draft' && <Button size="sm" onClick={() => publish.mutate({ id: rate.id })}>Publish</Button>}
                            {rate.status !== 'archived' && <Button size="sm" variant="light" onClick={() => archive.mutate({ id: rate.id })}>Archive</Button>}
                        </div>
                    );
                },
            }),
        ],
        [publish, archive],
    );

    const dormant = Object.entries(data?.channels ?? {}).filter(([, channel]) => !channel.metered);

    return (
        <Card actions={<Button size="sm" icon="ti ti-plus" onClick={() => setCreating(true)}>New rate</Button>}>
            {dormant.map(([channel, info]) => (
                <div key={channel} className="sx-banner sx-banner--trial">
                    <i className="ti ti-info-circle" aria-hidden="true" />
                    <span>
                        <b>{channel.toUpperCase()}</b> — {info.note}
                    </span>
                </div>
            ))}
            <DataTable
                data={data?.rates ?? []}
                columns={columns}
                loading={isLoading}
                error={isError}
                onRetry={() => void refetch()}
                hideSearch
                emptyIcon="ti ti-message-dollar"
                emptyTitle="No rates yet"
                emptyDescription="Enter each provider's rate as configured with that provider, and what you sell it for past a plan's allowance."
            />
            {creating && <RateDialog onClose={() => setCreating(false)} />}
        </Card>
    );
}

function RateDialog({ onClose }: { onClose: () => void }) {
    const save = useBillingMutation<Record<string, unknown>>('post', '/usage-rates');
    const [form, setForm] = useState({
        channel: 'whatsapp',
        provider: '',
        category: '',
        destination: '',
        billing_unit: 'message',
        cost: '',
        price: '',
        overage_policy: 'block',
        charge_failed: false,
        publish: true,
        from: '',
    });

    return (
        <ActionDialog
            title="New usage rate"
            subtitle="Published rates are never edited: a new one takes over from its date, and every charge keeps the rate it was made at."
            icon="ti ti-message-dollar"
            askReason={false}
            submitLabel="Save rate"
            onClose={onClose}
            submit={() =>
                save.mutateAsync({
                    channel: form.channel,
                    provider: form.provider || null,
                    category: form.category || null,
                    destination: form.destination || null,
                    billing_unit: form.billing_unit,
                    provider_cost_e4: rupeesToE4(form.cost) ?? -1,
                    price_e4: rupeesToE4(form.price) ?? -1,
                    overage_policy: form.overage_policy,
                    charge_failed: form.charge_failed,
                    status: form.publish ? 'published' : 'draft',
                    effective_from: form.from || null,
                })
            }
        >
            {(errors) => (
                <div className="row g-3 mb-2">
                    <div className="col-md-4">
                        <label className="form-label">Channel</label>
                        <select className="form-select" value={form.channel} onChange={(e) => setForm({ ...form, channel: e.target.value, billing_unit: { whatsapp: 'message', sms: 'segment', email: 'email', video: 'minute' }[e.target.value] ?? 'message' })}>
                            <option value="whatsapp">WhatsApp</option>
                            <option value="sms">SMS</option>
                            <option value="email">Email</option>
                            <option value="video">Video</option>
                        </select>
                    </div>
                    <div className="col-md-4">
                        <label className="form-label">Provider</label>
                        <input className="form-control" placeholder="Any" value={form.provider} onChange={(e) => setForm({ ...form, provider: e.target.value })} />
                    </div>
                    <div className="col-md-4">
                        <label className="form-label">Unit</label>
                        <select className="form-select" value={form.billing_unit} onChange={(e) => setForm({ ...form, billing_unit: e.target.value })}>
                            {['message', 'segment', 'email', 'minute', 'session'].map((unit) => <option key={unit} value={unit}>{unit}</option>)}
                        </select>
                    </div>
                    {(form.channel === 'whatsapp' || form.channel === 'sms') && (
                        <>
                            <div className="col-md-6">
                                <label className="form-label">Category</label>
                                <select className="form-select" value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })}>
                                    <option value="">Any</option>
                                    {(form.channel === 'whatsapp' ? ['marketing', 'utility', 'authentication', 'service'] : ['transactional', 'otp', 'promotional']).map((c) => (
                                        <option key={c} value={c}>{c}</option>
                                    ))}
                                </select>
                            </div>
                            <div className="col-md-6">
                                <label className="form-label">Destination country</label>
                                <input className="form-control" placeholder="Any (e.g. IN)" value={form.destination} onChange={(e) => setForm({ ...form, destination: e.target.value.toUpperCase() })} />
                            </div>
                        </>
                    )}
                    <div className="col-md-6">
                        <label className="form-label">Provider cost per unit (₹)</label>
                        <input className="form-control" placeholder="0.1150" value={form.cost} onChange={(e) => setForm({ ...form, cost: e.target.value })} />
                        <FieldError errors={errors} name="provider_cost_e4" />
                    </div>
                    <div className="col-md-6">
                        <label className="form-label">Sold at per unit (₹)</label>
                        <input className="form-control" placeholder="0.2500" value={form.price} onChange={(e) => setForm({ ...form, price: e.target.value })} />
                        <FieldError errors={errors} name="price_e4" />
                    </div>
                    <div className="col-md-6">
                        <label className="form-label">Past the allowance</label>
                        <select className="form-select" value={form.overage_policy} onChange={(e) => setForm({ ...form, overage_policy: e.target.value })}>
                            <option value="block">Block sending</option>
                            <option value="allow">Send, and charge at this rate</option>
                        </select>
                    </div>
                    <div className="col-md-6">
                        <label className="form-label">Takes effect</label>
                        <input type="datetime-local" className="form-control" value={form.from} onChange={(e) => setForm({ ...form, from: e.target.value })} />
                    </div>
                    <div className="col-12 sxa-checks">
                        <label><input type="checkbox" className="form-check-input" checked={form.charge_failed} onChange={(e) => setForm({ ...form, charge_failed: e.target.checked })} /> Charge for sends the provider failed</label>
                        <label><input type="checkbox" className="form-check-input" checked={form.publish} onChange={(e) => setForm({ ...form, publish: e.target.checked })} /> Publish now</label>
                    </div>
                </div>
            )}
        </ActionDialog>
    );
}
