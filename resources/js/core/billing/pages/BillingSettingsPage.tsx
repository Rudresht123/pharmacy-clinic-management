import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import { LoadingBlock } from '@/shared/components/ui/Feedback';
import { resolveErrorMessage } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import {
    useBillingNumbering,
    useBillingSettings,
    useSaveBillingNumbering,
    useSaveBillingSettings,
    type BillingSettings,
    type NumberedDocument,
} from '../api';

/**
 * How the organisation bills.
 *
 * Reading is `billing.view`; the save button is only offered to somebody
 * holding `billing.manage_settings` (an organisation-scoped capability that
 * branch roles never hold).
 *
 * Module-aware: pharmacy and laboratory triggers are hidden unless the
 * organisation runs those modules — an option that would never fire is worse
 * than no option.
 */
const TRIGGER_LABELS: Record<string, { label: string; description: string; module?: string }> = {
    checkin: {
        label: 'At check-in',
        description: 'Advance-payment clinics — the bill is drawn the moment the patient arrives.',
    },
    consultation: {
        label: 'After consultation',
        description: 'The common case — the doctor finishing draws the bill for the visit.',
    },
    pharmacy: {
        label: 'After pharmacy',
        description: 'Bill combines with what was dispensed. Only offered where a pharmacy runs.',
        module: 'pharmacy',
    },
    laboratory: {
        label: 'After laboratory',
        description: 'Bill drawn when the lab signs off. Only offered where a lab runs.',
        module: 'laboratory',
    },
    manual: {
        label: 'Manual only',
        description: 'The counter draws every invoice by hand — no automatic trigger.',
    },
};

const PAYMENT_BEHAVIOUR_LABELS: Record<string, { label: string; description: string }> = {
    full: { label: 'Full payment required', description: 'Nothing may be left owing on an invoice.' },
    partial: { label: 'Allow partial payment', description: 'A patient may pay some now, the rest later.' },
    credit: { label: 'Allow credit', description: 'A registered patient may leave a bill unpaid.' },
};

const ALL_METHODS = ['cash', 'card', 'upi', 'bank_transfer', 'online', 'cheque', 'other'];

const NUMBERED: { type: NumberedDocument; label: string }[] = [
    { type: 'invoice', label: 'Invoice' },
    { type: 'receipt', label: 'Receipt' },
    { type: 'refund', label: 'Refund' },
];

/** One branch's one series, as the prefix map keys it. */
const seriesKey = (locationId: number, type: NumberedDocument) => `${locationId}:${type}`;

const METHOD_LABELS: Record<string, string> = {
    cash: 'Cash',
    card: 'Card',
    upi: 'UPI',
    bank_transfer: 'Bank transfer',
    online: 'Online',
    cheque: 'Cheque',
    other: 'Other',
};

export default function BillingSettingsPage() {
    const navigate = useNavigate();
    const { can, modules } = useTenantAuth();
    const canManage = can('billing.manage_settings');
    const hasModule = (module: string) => modules.includes(module);

    const { data: settings, isLoading } = useBillingSettings();
    const save = useSaveBillingSettings();

    const { data: numbering } = useBillingNumbering();
    const saveNumbering = useSaveBillingNumbering();

    const [form, setForm] = useState<BillingSettings | null>(null);
    const [errorText, setErrorText] = useState<string | null>(null);

    // Every branch's prefix per document, as typed — keyed by seriesKey().
    const [prefixes, setPrefixes] = useState<Record<string, string>>({});

    useEffect(() => {
        if (settings) setForm(settings);
    }, [settings]);

    useEffect(() => {
        if (!numbering) return;

        setPrefixes(
            Object.fromEntries(
                numbering.branches.flatMap((branch) =>
                    NUMBERED.map(({ type }) => [
                        seriesKey(branch.location_id, type),
                        branch.series[type].prefix,
                    ]),
                ),
            ),
        );
    }, [numbering]);

    /** Only the series somebody actually changed — the rest are left alone. */
    const changedSeries = (numbering?.branches ?? []).flatMap((branch) =>
        NUMBERED.filter(
            ({ type }) =>
                (prefixes[seriesKey(branch.location_id, type)] ?? '') !== branch.series[type].prefix,
        ).map(({ type }) => ({
            location_id: branch.location_id,
            document_type: type,
            prefix: prefixes[seriesKey(branch.location_id, type)] ?? '',
        })),
    );

    if (isLoading || !form) {
        return <LoadingBlock label="Loading settings…" />;
    }

    function set<K extends keyof BillingSettings>(key: K, value: BillingSettings[K]) {
        setForm((prev) => (prev ? { ...prev, [key]: value } : prev));
    }

    function toggleMethod(method: string) {
        set(
            'payment_methods',
            form!.payment_methods.includes(method)
                ? form!.payment_methods.filter((m) => m !== method)
                : [...form!.payment_methods, method],
        );
    }

    async function submit(event: React.FormEvent) {
        event.preventDefault();
        setErrorText(null);

        try {
            const {
                triggers: _triggers,
                payment_behaviours: _payment_behaviours,
                ...payload
            } = form!;
            void _triggers;
            void _payment_behaviours;
            await save.mutateAsync(payload);

            // One Save for the whole page; the numbers go only if touched.
            if (changedSeries.length > 0) {
                await saveNumbering.mutateAsync(changedSeries);
            }

            notify.success('Billing settings saved');
        } catch (failure) {
            setErrorText(resolveErrorMessage(failure));
        }
    }

    return (
        <form onSubmit={submit}>
            <PageHeader
                title="Billing settings"
                subtitle="How the organisation raises and takes payment."
                icon="ti ti-adjustments"
                tone="teal"
                crumbs={[{ label: 'Settings' }, { label: 'Billing' }]}
                actions={
                    canManage ? (
                        <Button
                            type="submit"
                            icon="ti ti-check"
                            disabled={save.isPending || saveNumbering.isPending}
                        >
                            {save.isPending || saveNumbering.isPending ? 'Saving…' : 'Save'}
                        </Button>
                    ) : undefined
                }
            />

            {!canManage && (
                <div className="alert alert-info py-2">
                    You may read these settings; changing them is a head-office decision.
                </div>
            )}

            {errorText && <div className="alert alert-danger py-2">{errorText}</div>}

            <div className="row g-3">
                <div className="col-lg-8">
                    <Card>
                        <h6 className="mb-2">When to draw an invoice</h6>
                        <p className="text-muted fs-13 mb-3">
                            The one act of the visit that produces the bill. Only triggers whose
                            module is enabled here are offered.
                        </p>

                        {Object.entries(TRIGGER_LABELS).map(([value, meta]) => {
                            const disabled = Boolean(meta.module) && !hasModule(meta.module!);

                            return (
                                <label
                                    key={value}
                                    className={`d-flex gap-3 p-3 border rounded mb-2 ${disabled ? 'opacity-50' : ''} ${form.default_trigger === value ? 'border-primary bg-primary-subtle' : ''}`}
                                    style={{ cursor: disabled ? 'not-allowed' : 'pointer' }}
                                >
                                    <input
                                        type="radio"
                                        name="default_trigger"
                                        value={value}
                                        checked={form.default_trigger === value}
                                        onChange={() => set('default_trigger', value)}
                                        disabled={disabled || !canManage}
                                    />
                                    <div className="flex-grow-1">
                                        <b>{meta.label}</b>
                                        <span className="dr-sub d-block">
                                            {disabled
                                                ? `Requires the ${meta.module} module — not enabled here.`
                                                : meta.description}
                                        </span>
                                    </div>
                                </label>
                            );
                        })}
                    </Card>

                    <Card className="mt-3">
                        <h6 className="mb-2">Payment behaviour</h6>
                        <p className="text-muted fs-13 mb-3">
                            What the counter is allowed to do when the money is not all there.
                        </p>

                        {Object.entries(PAYMENT_BEHAVIOUR_LABELS).map(([value, meta]) => (
                            <label
                                key={value}
                                className={`d-flex gap-3 p-3 border rounded mb-2 ${form.payment_behaviour === value ? 'border-primary bg-primary-subtle' : ''}`}
                                style={{ cursor: 'pointer' }}
                            >
                                <input
                                    type="radio"
                                    name="payment_behaviour"
                                    value={value}
                                    checked={form.payment_behaviour === value}
                                    onChange={() => set('payment_behaviour', value as BillingSettings['payment_behaviour'])}
                                    disabled={!canManage}
                                />
                                <div>
                                    <b>{meta.label}</b>
                                    <span className="dr-sub d-block">{meta.description}</span>
                                </div>
                            </label>
                        ))}
                    </Card>

                    {/*
                        The price list, linked rather than given a menu row of
                        its own: it is set up once and then read by the
                        software, so a permanent row crowded the screens that
                        are actually worked all day.
                    */}
                    <Card className="mt-3">
                        <div className="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 className="mb-1">Services &amp; price list</h6>
                                <p className="text-muted fs-13 mb-0">
                                    What the clinic charges for besides the doctor&rsquo;s own fee —
                                    registration, injections, procedures.
                                </p>
                            </div>
                            <Button
                                variant="light"
                                icon="ti ti-list-details"
                                type="button"
                                onClick={() => navigate('/billing/services')}
                            >
                                Open
                            </Button>
                        </div>
                    </Card>

                    <Card className="mt-3">
                        <h6 className="mb-2">Payment methods accepted</h6>
                        <p className="text-muted fs-13 mb-3">
                            The till only offers ticks below when taking a payment.
                        </p>

                        <div className="row g-2">
                            {ALL_METHODS.map((method) => (
                                <div className="col-6 col-md-4" key={method}>
                                    <label className="form-check">
                                        <input
                                            type="checkbox"
                                            className="form-check-input"
                                            checked={form.payment_methods.includes(method)}
                                            onChange={() => toggleMethod(method)}
                                            disabled={!canManage}
                                        />
                                        <span className="form-check-label">{METHOD_LABELS[method]}</span>
                                    </label>
                                </div>
                            ))}
                        </div>
                    </Card>

                    {/*
                        Branch-wise, because a GST series belongs to a GSTIN
                        and two branches in two states have two. The number
                        itself is the database's, taken as the bill is saved;
                        all anybody chooses here is what it starts with.
                    */}
                    <Card className="mt-3">
                        <h6 className="mb-2">Invoice &amp; receipt numbers</h6>
                        <p className="text-muted fs-13 mb-3">
                            Every branch numbers its own bills, and each series starts again on 1 April.
                            A new prefix applies from the next bill — nothing already issued is renumbered.
                        </p>

                        {!numbering ? (
                            <LoadingBlock label="Loading numbering…" />
                        ) : numbering.branches.length === 0 ? (
                            <p className="dpt-none">No branches yet.</p>
                        ) : (
                            <div className="dpt-frame">
                                <table className="dpt-table">
                                    <thead>
                                        <tr>
                                            <th>Branch</th>
                                            {NUMBERED.map(({ type, label }) => (
                                                <th key={type}>{label} prefix</th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {numbering.branches.map((branch) => (
                                            <tr key={branch.location_id}>
                                                <td>
                                                    <b>{branch.name}</b>
                                                    <span className="dr-sub d-block">{branch.code}</span>
                                                </td>

                                                {NUMBERED.map(({ type, label }) => {
                                                    const key = seriesKey(branch.location_id, type);
                                                    const prefix = prefixes[key] ?? '';
                                                    const series = branch.series[type];

                                                    return (
                                                        <td key={type}>
                                                            <input
                                                                type="text"
                                                                className="form-control form-control-sm"
                                                                value={prefix}
                                                                onChange={(event) =>
                                                                    setPrefixes((current) => ({
                                                                        ...current,
                                                                        [key]: event.target.value.toUpperCase(),
                                                                    }))
                                                                }
                                                                maxLength={20}
                                                                disabled={!canManage}
                                                                aria-label={`${branch.name} ${label.toLowerCase()} prefix`}
                                                            />
                                                            <small className="text-muted dpt-num">
                                                                Next: {prefix || '…'}/{numbering.financial_year}/
                                                                {series.next_sequence}
                                                            </small>
                                                        </td>
                                                    );
                                                })}
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </Card>
                </div>

                <div className="col-lg-4">
                    {/*
                        The organisation-wide prefix that used to sit here is
                        gone from the screen: numbering is per branch now, in
                        its own card. The stored value still seeds the invoice
                        part of a new branch's default (CODE/INV).
                    */}
                    <Card>
                        <h6 className="mb-3">Currency</h6>

                        <div className="row g-2">
                            <div className="col-8">
                                <label className="form-label fs-13">Currency code</label>
                                <input
                                    type="text"
                                    className="form-control"
                                    value={form.currency_code}
                                    onChange={(event) => set('currency_code', event.target.value.toUpperCase())}
                                    maxLength={3}
                                    disabled={!canManage}
                                />
                            </div>
                            <div className="col-4">
                                <label className="form-label fs-13">Symbol</label>
                                <input
                                    type="text"
                                    className="form-control"
                                    value={form.currency_symbol}
                                    onChange={(event) => set('currency_symbol', event.target.value)}
                                    maxLength={4}
                                    disabled={!canManage}
                                />
                            </div>
                        </div>
                    </Card>

                    <Card className="mt-3">
                        <h6 className="mb-3">Tax</h6>
                        <div className="mb-3">
                            <label className="form-label fs-13">Default tax %</label>
                            <input
                                type="number"
                                step="0.01"
                                min="0"
                                max="100"
                                className="form-control"
                                value={form.default_tax_percent}
                                onChange={(event) =>
                                    set('default_tax_percent', parseFloat(event.target.value) || 0)
                                }
                                disabled={!canManage}
                            />
                        </div>
                        <label className="form-check">
                            <input
                                type="checkbox"
                                className="form-check-input"
                                checked={form.prices_include_tax}
                                onChange={(event) => set('prices_include_tax', event.target.checked)}
                                disabled={!canManage}
                            />
                            <span className="form-check-label">Prices already include tax</span>
                        </label>
                    </Card>

                    <Card className="mt-3">
                        <h6 className="mb-3">Invoice text</h6>
                        <label className="form-check mb-3">
                            <input
                                type="checkbox"
                                className="form-check-input"
                                checked={form.allow_edit_before_payment}
                                onChange={(event) =>
                                    set('allow_edit_before_payment', event.target.checked)
                                }
                                disabled={!canManage}
                            />
                            <span className="form-check-label">Allow staff to edit an unpaid invoice</span>
                        </label>

                        <div className="mb-3">
                            <label className="form-label fs-13">Terms (printed on invoice)</label>
                            <textarea
                                className="form-control"
                                rows={3}
                                value={form.terms ?? ''}
                                onChange={(event) => set('terms', event.target.value || null)}
                                maxLength={2000}
                                disabled={!canManage}
                            />
                        </div>

                        <div>
                            <label className="form-label fs-13">Footer</label>
                            <textarea
                                className="form-control"
                                rows={2}
                                value={form.footer ?? ''}
                                onChange={(event) => set('footer', event.target.value || null)}
                                maxLength={500}
                                disabled={!canManage}
                            />
                        </div>
                    </Card>
                </div>
            </div>
        </form>
    );
}
