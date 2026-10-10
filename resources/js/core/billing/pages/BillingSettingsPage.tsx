import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import { LoadingBlock } from '@/shared/components/ui/Feedback';
import { SearchableSelect } from '@/shared/components/form/SearchableSelect';
import { resolveErrorMessage } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import { cn } from '@/shared/utils/cn';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import {
    useBillingNumbering,
    useBillingSettings,
    useSaveBillingNumbering,
    useSaveBillingSettings,
    type BillingNumbering,
    type BillingSettings,
    type NumberedDocument,
} from '../api';

/**
 * How the organisation bills.
 *
 * Reading is `billing.view`; changing anything needs `billing.manage_settings`,
 * an organisation-scoped capability branch roles never hold — so a branch user
 * sees every section read-only.
 */

type Section = 'timing' | 'payments' | 'tax' | 'numbers' | 'print';

const SECTION_LABELS: Record<Section, string> = {
    timing: 'Bill timing',
    payments: 'Payments',
    tax: 'Tax & currency',
    numbers: 'Bill numbers',
    print: 'Printed bill',
};

const SECTION_ORDER: Section[] = ['timing', 'payments', 'tax', 'numbers', 'print'];

interface Choice {
    value: string;
    label: string;
    description: string;
    icon: string;
    module?: string;
    tag?: string;
}

const TRIGGERS: Choice[] = [
    {
        value: 'checkin',
        icon: 'ti ti-door-enter',
        label: 'When the patient checks in',
        description: 'Made as they arrive. Suits clinics that take payment upfront.',
    },
    {
        value: 'consultation',
        icon: 'ti ti-stethoscope',
        label: 'After the consultation',
        description: 'Made when the doctor finishes the visit.',
        tag: 'Most common',
    },
    {
        value: 'pharmacy',
        icon: 'ti ti-pill',
        label: 'After medicines are given',
        description: 'One bill for the visit and the medicines dispensed with it.',
        module: 'pharmacy',
    },
    {
        value: 'laboratory',
        icon: 'ti ti-flask',
        label: 'After lab tests',
        description: 'Made once the lab signs off the results.',
        module: 'laboratory',
    },
    {
        value: 'manual',
        icon: 'ti ti-hand-finger',
        label: 'Only when staff make it',
        description: 'Nothing automatic — the counter creates every bill by hand.',
    },
];

const MODULE_NAMES: Record<string, string> = { pharmacy: 'Pharmacy', laboratory: 'Laboratory' };

const BEHAVIOURS: Choice[] = [
    {
        value: 'full',
        icon: 'ti ti-circle-check',
        label: 'Pay in full',
        description: 'The whole bill is settled before the patient leaves.',
    },
    {
        value: 'partial',
        icon: 'ti ti-chart-pie',
        label: 'Part payment allowed',
        description: 'Patients may pay some now and the rest later.',
    },
    {
        value: 'credit',
        icon: 'ti ti-clock-dollar',
        label: 'Pay later (credit)',
        description: 'Registered patients may leave unpaid; the amount shows under Outstanding.',
    },
];

const METHODS = [
    { value: 'cash', label: 'Cash', icon: 'ti ti-cash' },
    { value: 'card', label: 'Card', icon: 'ti ti-credit-card' },
    { value: 'upi', label: 'UPI', icon: 'ti ti-qrcode' },
    { value: 'bank_transfer', label: 'Bank transfer', icon: 'ti ti-building-bank' },
    { value: 'online', label: 'Online', icon: 'ti ti-world' },
    { value: 'cheque', label: 'Cheque', icon: 'ti ti-writing' },
    { value: 'other', label: 'Other', icon: 'ti ti-dots' },
];

const CURRENCIES = [
    { code: 'INR', symbol: '₹', name: 'Indian rupee' },
    { code: 'USD', symbol: '$', name: 'US dollar' },
    { code: 'EUR', symbol: '€', name: 'Euro' },
    { code: 'GBP', symbol: '£', name: 'British pound' },
    { code: 'AED', symbol: 'AED', name: 'UAE dirham' },
    { code: 'NPR', symbol: 'Rs', name: 'Nepalese rupee' },
    { code: 'BDT', symbol: '৳', name: 'Bangladeshi taka' },
    { code: 'LKR', symbol: 'Rs', name: 'Sri Lankan rupee' },
];

const QUICK_RATES = [0, 5, 12, 18];

const NUMBERED: { type: NumberedDocument; label: string }[] = [
    { type: 'invoice', label: 'Bills' },
    { type: 'receipt', label: 'Receipts' },
    { type: 'refund', label: 'Refunds' },
];

// Mirrors the server rule in BillingNumberingController.
const PREFIX_PATTERN = /^[A-Z0-9](?:[A-Z0-9/-]*[A-Z0-9])?$/;

const TERMS_MAX = 2000;
const FOOTER_MAX = 500;

type Draft = Omit<BillingSettings, 'triggers' | 'payment_behaviours' | 'default_tax_percent'> & {
    // Kept as typed so "12." can be entered on the way to "12.5".
    default_tax_percent: string;
};

type SetDraft = <K extends keyof Draft>(key: K, value: Draft[K]) => void;

const seriesKey = (locationId: number, type: NumberedDocument) => `${locationId}:${type}`;

function toDraft(settings: BillingSettings): Draft {
    const { triggers: _triggers, payment_behaviours: _behaviours, ...rest } = settings;
    void _triggers;
    void _behaviours;

    return { ...rest, default_tax_percent: String(settings.default_tax_percent) };
}

function toPrefixes(numbering: BillingNumbering): Record<string, string> {
    return Object.fromEntries(
        numbering.branches.flatMap((branch) =>
            NUMBERED.map(({ type }) => [seriesKey(branch.location_id, type), branch.series[type].prefix]),
        ),
    );
}

function sameSet(a: string[], b: string[]): boolean {
    return a.length === b.length && a.every((item) => b.includes(item));
}

function joinWords(words: string[]): string {
    if (words.length <= 1) return words.join('');

    return `${words.slice(0, -1).join(', ')} and ${words[words.length - 1]}`;
}

function prefixProblem(prefix: string): string | null {
    if (!prefix) return 'A prefix is needed.';
    if (!PREFIX_PATTERN.test(prefix)) {
        return 'Use letters, numbers, / or - and start and end with a letter or number.';
    }

    return null;
}

function money(amount: number, symbol: string, code: string): string {
    const rounded = Math.round(amount * 100) / 100;

    return `${symbol}${rounded.toLocaleString(code === 'INR' ? 'en-IN' : undefined, {
        minimumFractionDigits: Number.isInteger(rounded) ? 0 : 2,
        maximumFractionDigits: 2,
    })}`;
}

export default function BillingSettingsPage() {
    const navigate = useNavigate();
    const { can, modules } = useTenantAuth();
    const canManage = can('billing.manage_settings');

    const { data: settings, isLoading, refetch: refetchSettings } = useBillingSettings();
    const save = useSaveBillingSettings();
    const { data: numbering, refetch: refetchNumbering } = useBillingNumbering();
    const saveNumbering = useSaveBillingNumbering();

    const [draft, setDraft] = useState<Draft | null>(null);
    const [prefixes, setPrefixes] = useState<Record<string, string>>({});
    const [errorText, setErrorText] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (settings) setDraft(toDraft(settings));
    }, [settings]);

    useEffect(() => {
        if (numbering) setPrefixes(toPrefixes(numbering));
    }, [numbering]);

    if (isLoading || !settings || !draft) {
        return <LoadingBlock label="Loading settings…" />;
    }

    const set: SetDraft = (key, value) =>
        setDraft((current) => (current ? { ...current, [key]: value } : current));

    const changedSeries = (numbering?.branches ?? []).flatMap((branch) =>
        NUMBERED.filter(
            ({ type }) => (prefixes[seriesKey(branch.location_id, type)] ?? '') !== branch.series[type].prefix,
        ).map(({ type }) => ({
            location_id: branch.location_id,
            document_type: type,
            prefix: prefixes[seriesKey(branch.location_id, type)] ?? '',
        })),
    );

    const taxRate = Number(draft.default_tax_percent);

    const dirtyBySection: Record<Section, boolean> = {
        timing:
            draft.default_trigger !== settings.default_trigger ||
            draft.allow_edit_before_payment !== settings.allow_edit_before_payment,
        payments:
            draft.payment_behaviour !== settings.payment_behaviour ||
            !sameSet(draft.payment_methods, settings.payment_methods),
        tax:
            draft.currency_code !== settings.currency_code ||
            draft.currency_symbol !== settings.currency_symbol ||
            draft.prices_include_tax !== settings.prices_include_tax ||
            draft.default_tax_percent.trim() === '' ||
            taxRate !== settings.default_tax_percent,
        numbers: changedSeries.length > 0,
        print: (draft.terms ?? '') !== (settings.terms ?? '') || (draft.footer ?? '') !== (settings.footer ?? ''),
    };

    const dirtySections = SECTION_ORDER.filter((section) => dirtyBySection[section]);
    const settingsDirty = dirtySections.some((section) => section !== 'numbers');
    const dirty = dirtySections.length > 0;

    const problems: Partial<Record<Section, string>> = {};

    if (draft.payment_methods.length === 0) {
        problems.payments = 'Switch on at least one way to pay.';
    }

    if (!/^[A-Z]{3}$/.test(draft.currency_code) || !draft.currency_symbol.trim()) {
        problems.tax = 'Choose a currency and the symbol printed on bills.';
    } else if (draft.default_tax_percent.trim() === '' || Number.isNaN(taxRate) || taxRate < 0 || taxRate > 100) {
        problems.tax = 'The tax rate has to be a number from 0 to 100.';
    }

    if (changedSeries.some((series) => prefixProblem(series.prefix))) {
        problems.numbers = 'One of the bill number prefixes needs fixing.';
    }

    function discard() {
        setDraft(toDraft(settings!));
        if (numbering) setPrefixes(toPrefixes(numbering));
        setErrorText(null);
    }

    async function submit(event: React.FormEvent) {
        event.preventDefault();

        if (!canManage || !dirty) return;

        const firstProblem = SECTION_ORDER.find((section) => problems[section]);

        if (firstProblem) {
            document
                .getElementById(`bset-${firstProblem}`)
                ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            notify.error(problems[firstProblem]!);

            return;
        }

        setErrorText(null);
        setSaving(true);

        try {
            if (settingsDirty) {
                await save.mutateAsync({
                    ...draft!,
                    default_tax_percent: taxRate,
                    terms: draft!.terms?.trim() || null,
                    footer: draft!.footer?.trim() || null,
                });
            }

            if (changedSeries.length > 0) {
                await saveNumbering.mutateAsync(changedSeries);
            }

            // Otherwise the bar reads "Unsaved changes" until the refetch lands.
            await Promise.all([
                settingsDirty ? refetchSettings() : null,
                changedSeries.length > 0 ? refetchNumbering() : null,
            ]);

            notify.success('Billing settings saved');
        } catch (failure) {
            setErrorText(resolveErrorMessage(failure));
        } finally {
            setSaving(false);
        }
    }

    const triggers = TRIGGERS.filter((choice) => !settings.triggers?.length || settings.triggers.includes(choice.value));

    const lockedReason = (choice: Choice) =>
        choice.module && !modules.includes(choice.module)
            ? `Needs the ${MODULE_NAMES[choice.module] ?? choice.module} module, which isn't on for your organisation.`
            : null;

    const accepted = METHODS.filter((method) => draft.payment_methods.includes(method.value));

    return (
        <form onSubmit={submit} noValidate>
            <PageHeader
                title="Billing settings"
                subtitle="How your clinic makes bills and takes payment."
                icon="ti ti-adjustments"
                tone="teal"
                crumbs={[{ label: 'Settings' }, { label: 'Billing' }]}
                actions={
                    <Button
                        type="button"
                        variant="light"
                        icon="ti ti-list-details"
                        onClick={() => navigate('/billing/services')}
                    >
                        Services &amp; prices
                    </Button>
                }
            />

            {!canManage && (
                <div className="bset-readonly">
                    <i className="ti ti-lock" />
                    <span>
                        You can view these settings. Only someone with organisation-level billing access can
                        change them.
                    </span>
                </div>
            )}

            {errorText && <div className="alert alert-danger py-2">{errorText}</div>}

            <fieldset disabled={!canManage} className="border-0 p-0 m-0">
                <div id="bset-timing" className="bset-section">
                    <Card
                        title="When is the bill made?"
                        icon="ti ti-receipt"
                        description="Pick the moment in a visit that creates the patient's bill automatically."
                    >
                        <ChoiceGrid
                            name="default_trigger"
                            label="When is the bill made?"
                            choices={triggers}
                            value={draft.default_trigger}
                            onChange={(value) => set('default_trigger', value)}
                            lockedReason={lockedReason}
                        />

                        <div className="bset-divider" />

                        <Toggle
                            id="allow_edit_before_payment"
                            label="Let staff edit a bill until it is paid"
                            hint="Lets the counter add a forgotten service or fix a price before the patient pays."
                            checked={draft.allow_edit_before_payment}
                            onChange={(checked) => set('allow_edit_before_payment', checked)}
                        />
                    </Card>
                </div>

                <div id="bset-payments" className="bset-section mt-3">
                    <Card
                        title="Payments"
                        icon="ti ti-wallet"
                        description="How much a patient must pay, and the ways the counter accepts."
                    >
                        <h6 className="bset-subhead">Can a patient leave without paying in full?</h6>
                        <ChoiceGrid
                            name="payment_behaviour"
                            label="Can a patient leave without paying in full?"
                            choices={BEHAVIOURS}
                            value={draft.payment_behaviour}
                            onChange={(value) => set('payment_behaviour', value as Draft['payment_behaviour'])}
                        />

                        <div className="bset-divider" />

                        <h6 className="bset-subhead">Accepted payment methods</h6>
                        <div className="bset-methods" role="group" aria-label="Accepted payment methods">
                            {METHODS.map((method) => {
                                const on = draft.payment_methods.includes(method.value);
                                const last = on && draft.payment_methods.length === 1;

                                return (
                                    <label
                                        key={method.value}
                                        className={cn('bset-method', on && 'is-on')}
                                        title={last ? 'Keep at least one way to pay switched on.' : undefined}
                                    >
                                        <input
                                            type="checkbox"
                                            checked={on}
                                            onChange={() =>
                                                last
                                                    ? notify.error('Keep at least one way to pay switched on.')
                                                    : set(
                                                          'payment_methods',
                                                          on
                                                              ? draft.payment_methods.filter((m) => m !== method.value)
                                                              : [...draft.payment_methods, method.value],
                                                      )
                                            }
                                        />
                                        <i className={method.icon} />
                                        <span>{method.label}</span>
                                        <span className="bset-method-check" aria-hidden="true">
                                            <i className="ti ti-check" />
                                        </span>
                                    </label>
                                );
                            })}
                        </div>

                        <p className="bset-note">
                            <i className="ti ti-info-circle" />
                            <span>
                                The counter will offer{' '}
                                <b>{joinWords(accepted.map((method) => method.label)) || 'nothing yet'}</b> when
                                taking a payment.
                            </span>
                        </p>
                    </Card>
                </div>

                <div id="bset-tax" className="bset-section mt-3">
                    <TaxSection draft={draft} set={set} problem={problems.tax} />
                </div>

                <div id="bset-numbers" className="bset-section mt-3">
                    <NumbersSection numbering={numbering} prefixes={prefixes} setPrefixes={setPrefixes} />
                </div>

                <div id="bset-print" className="bset-section mt-3">
                    <PrintSection draft={draft} set={set} />
                </div>
            </fieldset>

            {canManage && (
                <div className="form-actions">
                    <span className="form-actions-note">
                        {dirty ? (
                            <>
                                <span className="bset-dot" aria-hidden="true" />
                                Unsaved changes in {joinWords(dirtySections.map((section) => SECTION_LABELS[section]))}.
                            </>
                        ) : (
                            'Changes apply to new bills only — anything already issued stays as it is.'
                        )}
                    </span>

                    {dirty && (
                        <Button
                            type="button"
                            variant="light"
                            icon="ti ti-arrow-back-up"
                            onClick={discard}
                            disabled={saving}
                        >
                            Discard
                        </Button>
                    )}

                    <Button type="submit" icon="ti ti-device-floppy" loading={saving} disabled={!dirty}>
                        Save changes
                    </Button>
                </div>
            )}
        </form>
    );
}

function ChoiceGrid({
    name,
    label,
    choices,
    value,
    onChange,
    lockedReason,
}: {
    name: string;
    label: string;
    choices: Choice[];
    value: string;
    onChange: (value: string) => void;
    lockedReason?: (choice: Choice) => string | null;
}) {
    return (
        <div className="bset-choices" role="radiogroup" aria-label={label}>
            {choices.map((choice) => {
                const locked = lockedReason?.(choice) ?? null;
                const on = value === choice.value;

                return (
                    <label key={choice.value} className={cn('bset-choice', on && 'is-on', locked && 'is-locked')}>
                        <input
                            type="radio"
                            className="bset-choice-input"
                            name={name}
                            value={choice.value}
                            checked={on}
                            disabled={Boolean(locked)}
                            onChange={() => onChange(choice.value)}
                        />
                        <span className="bset-choice-icon" aria-hidden="true">
                            <i className={choice.icon} />
                        </span>
                        <span className="bset-choice-text">
                            <span className="bset-choice-title">{choice.label}</span>
                            {choice.tag && <span className="bset-tag">{choice.tag}</span>}
                            <span className="bset-choice-desc">{locked ?? choice.description}</span>
                        </span>
                        <span className="bset-choice-mark" aria-hidden="true">
                            <i className={locked ? 'ti ti-lock' : 'ti ti-check'} />
                        </span>
                    </label>
                );
            })}
        </div>
    );
}

function Toggle({
    id,
    label,
    hint,
    checked,
    onChange,
}: {
    id: string;
    label: string;
    hint?: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
}) {
    return (
        <div className="switch-row">
            <div className="switch-row-text">
                <label className="switch-row-label" htmlFor={id}>
                    {label}
                </label>
                {hint && <small className="switch-row-hint">{hint}</small>}
            </div>

            <div className="form-check form-switch">
                <input
                    id={id}
                    type="checkbox"
                    className="form-check-input"
                    checked={checked}
                    onChange={(event) => onChange(event.target.checked)}
                />
            </div>
        </div>
    );
}

function TaxSection({ draft, set, problem }: { draft: Draft; set: SetDraft; problem?: string }) {
    const rate = Number(draft.default_tax_percent);
    const validRate = draft.default_tax_percent.trim() !== '' && !Number.isNaN(rate) && rate >= 0 && rate <= 100;
    const price = 500;
    const show = (amount: number) => money(amount, draft.currency_symbol, draft.currency_code);

    const currencyOptions = [
        ...CURRENCIES.map((currency) => ({
            value: currency.code,
            label: `${currency.name} (${currency.code})`,
            hint: currency.symbol,
        })),
        ...(CURRENCIES.some((currency) => currency.code === draft.currency_code)
            ? []
            : [{ value: draft.currency_code, label: draft.currency_code }]),
    ];

    let pays = show(price);
    let detail = 'No tax is added.';

    if (validRate && rate > 0) {
        if (draft.prices_include_tax) {
            detail = `${show(price - price / (1 + rate / 100))} of it is ${rate}% tax.`;
        } else {
            const tax = (price * rate) / 100;
            pays = show(price + tax);
            detail = `${show(price)} + ${show(tax)} tax at ${rate}%.`;
        }
    }

    return (
        <div className="row g-3">
            <div className="col-lg-5">
                <Card className="h-100" title="Currency" icon="ti ti-coin" description="What every bill is charged in.">
                    <label className="form-label" htmlFor="currency_code">
                        Currency
                    </label>
                    <SearchableSelect
                        id="currency_code"
                        value={draft.currency_code}
                        options={currencyOptions}
                        onChange={(code) => {
                            set('currency_code', code);

                            const known = CURRENCIES.find((currency) => currency.code === code);
                            if (known) set('currency_symbol', known.symbol);
                        }}
                    />

                    <label className="form-label mt-3" htmlFor="currency_symbol">
                        Symbol on bills
                    </label>
                    <input
                        id="currency_symbol"
                        type="text"
                        className={cn('form-control bset-symbol', !draft.currency_symbol.trim() && 'is-invalid')}
                        value={draft.currency_symbol}
                        onChange={(event) => set('currency_symbol', event.target.value)}
                        maxLength={4}
                    />
                    <small className="text-muted d-block mt-1">Printed before every amount, e.g. {show(1250)}.</small>
                </Card>
            </div>

            <div className="col-lg-7">
                <Card
                    className="h-100"
                    title="Tax"
                    icon="ti ti-receipt-tax"
                    description="Used on a bill line that doesn't carry a rate of its own."
                >
                    <label className="form-label" htmlFor="default_tax_percent">
                        Default tax rate
                    </label>
                    <div className="bset-rate-row">
                        <div className="input-group bset-rate">
                            <input
                                id="default_tax_percent"
                                type="text"
                                inputMode="decimal"
                                className={cn('form-control', !validRate && 'is-invalid')}
                                value={draft.default_tax_percent}
                                onChange={(event) =>
                                    set('default_tax_percent', event.target.value.replace(/[^0-9.]/g, ''))
                                }
                            />
                            <span className="input-group-text">%</span>
                        </div>

                        <div className="bset-quick" role="group" aria-label="Common tax rates">
                            {QUICK_RATES.map((quick) => (
                                <button
                                    key={quick}
                                    type="button"
                                    className={cn('bset-quick-btn', validRate && rate === quick && 'is-on')}
                                    onClick={() => set('default_tax_percent', String(quick))}
                                >
                                    {quick === 0 ? 'No tax' : `${quick}%`}
                                </button>
                            ))}
                        </div>
                    </div>

                    {problem && <small className="bset-field-error">{problem}</small>}

                    <div className="bset-divider" />

                    <Toggle
                        id="prices_include_tax"
                        label="Prices already include tax"
                        hint={
                            draft.prices_include_tax
                                ? 'The price you set is what the patient pays; the tax is worked out of it.'
                                : 'Tax is added on top of the price you set.'
                        }
                        checked={draft.prices_include_tax}
                        onChange={(checked) => set('prices_include_tax', checked)}
                    />

                    <div className="bset-example">
                        <span className="bset-example-label">How a bill will look</span>A {show(price)} service →
                        the patient pays <b>{pays}</b>
                        <small>{detail}</small>
                    </div>
                </Card>
            </div>
        </div>
    );
}

function NumbersSection({
    numbering,
    prefixes,
    setPrefixes,
}: {
    numbering: BillingNumbering | undefined;
    prefixes: Record<string, string>;
    setPrefixes: React.Dispatch<React.SetStateAction<Record<string, string>>>;
}) {
    return (
        <Card title="Bill numbers" icon="ti ti-hash" description="Every branch numbers its own bills, receipts and refunds.">
            {!numbering ? (
                <LoadingBlock label="Loading numbering…" />
            ) : numbering.branches.length === 0 ? (
                <p className="bset-note mt-0">
                    <i className="ti ti-info-circle" />
                    <span>Add a branch first — numbering is set up per branch.</span>
                </p>
            ) : (
                <>
                    <p className="bset-note mt-0 mb-3">
                        <i className="ti ti-calendar-repeat" />
                        <span>
                            Numbers start again every 1 April — this financial year is{' '}
                            <b>{numbering.financial_year}</b>. A new prefix is used from the next bill; nothing
                            already issued is renumbered.
                        </span>
                    </p>

                    <div className="dpt-frame">
                        <table className="bset-table">
                            <thead>
                                <tr>
                                    <th>Branch</th>
                                    {NUMBERED.map(({ type, label }) => (
                                        <th key={type}>{label}</th>
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
                                            const problem =
                                                prefix !== branch.series[type].prefix ? prefixProblem(prefix) : null;

                                            return (
                                                <td key={type}>
                                                    <input
                                                        type="text"
                                                        className={cn(
                                                            'form-control form-control-sm bset-prefix',
                                                            problem && 'is-invalid',
                                                        )}
                                                        value={prefix}
                                                        onChange={(event) =>
                                                            setPrefixes((current) => ({
                                                                ...current,
                                                                [key]: event.target.value.toUpperCase().replace(/\s+/g, ''),
                                                            }))
                                                        }
                                                        maxLength={20}
                                                        aria-label={`${branch.name} ${label.toLowerCase()} prefix`}
                                                    />
                                                    {problem ? (
                                                        <small className="bset-field-error">{problem}</small>
                                                    ) : (
                                                        <span className="bset-next">
                                                            Next: {prefix || '…'}/{numbering.financial_year}/
                                                            {branch.series[type].next_sequence}
                                                        </span>
                                                    )}
                                                </td>
                                            );
                                        })}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </>
            )}
        </Card>
    );
}

function PrintSection({ draft, set }: { draft: Draft; set: SetDraft }) {
    const terms = draft.terms ?? '';
    const footer = draft.footer ?? '';
    const show = (amount: number) => money(amount, draft.currency_symbol, draft.currency_code);

    return (
        <div className="row g-3">
            <div className="col-lg-7">
                <Card
                    className="h-100"
                    title="Printed bill"
                    icon="ti ti-printer"
                    description="Your own wording, printed at the bottom of every bill."
                >
                    <div className="bset-label-row">
                        <label className="form-label" htmlFor="terms">
                            Terms &amp; conditions
                        </label>
                        <span className="bset-count">
                            {terms.length}/{TERMS_MAX}
                        </span>
                    </div>
                    <textarea
                        id="terms"
                        className="form-control"
                        rows={4}
                        value={terms}
                        maxLength={TERMS_MAX}
                        placeholder="e.g. Fees once paid are not refundable. Please keep this bill for your records."
                        onChange={(event) => set('terms', event.target.value || null)}
                    />

                    <div className="bset-label-row mt-3">
                        <label className="form-label" htmlFor="footer">
                            Footer message
                        </label>
                        <span className="bset-count">
                            {footer.length}/{FOOTER_MAX}
                        </span>
                    </div>
                    <textarea
                        id="footer"
                        className="form-control"
                        rows={2}
                        value={footer}
                        maxLength={FOOTER_MAX}
                        placeholder="e.g. Thank you for visiting — get well soon!"
                        onChange={(event) => set('footer', event.target.value || null)}
                    />
                </Card>
            </div>

            <div className="col-lg-5">
                <Card
                    className="h-100"
                    title="Preview"
                    icon="ti ti-eye"
                    description="The bottom of a bill, as your patients will see it."
                >
                    <div className="bset-paper" aria-label="Preview of the bottom of a printed bill">
                        <div className="bset-paper-row">
                            <span>Consultation</span>
                            <span>{show(500)}</span>
                        </div>
                        <div className="bset-paper-row">
                            <span>Registration</span>
                            <span>{show(100)}</span>
                        </div>
                        <div className="bset-paper-row bset-paper-total">
                            <span>Total</span>
                            <span>{show(600)}</span>
                        </div>

                        <div className="bset-paper-terms">
                            <b>Terms &amp; conditions</b>
                            {terms.trim() ? terms : <span className="bset-paper-empty">Nothing printed here yet.</span>}
                        </div>

                        <div className="bset-paper-footer">
                            {footer.trim() ? footer : <span className="bset-paper-empty">No footer message.</span>}
                        </div>
                    </div>
                </Card>
            </div>
        </div>
    );
}
