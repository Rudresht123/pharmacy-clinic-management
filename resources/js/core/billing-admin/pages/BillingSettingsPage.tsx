import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import { ErrorState, LoadingBlock } from '@/shared/components/ui/Feedback';
import { getValidationErrors, resolveErrorMessage } from '@/shared/api/http';
import { formatShortDay } from '@/core/subscription/money';
import { useBillingMutation, useBillingSettings, usePlans, type BillingSettings } from '../api';
import { FieldError, StatusChip } from '../components/Common';

type Section = 'general' | 'payments' | 'invoices' | 'tax' | 'notifications' | 'integrations';
type Settings = BillingSettings['settings'];

const SECTIONS: Array<{ key: Section; icon: string; title: string; caption: string; blurb: string }> = [
    { key: 'general', icon: 'ti ti-building', title: 'General', caption: 'Company, timezone, year', blurb: 'Who issues the invoices, and when the financial year starts.' },
    { key: 'payments', icon: 'ti ti-credit-card', title: 'Payment Methods', caption: 'Razorpay, bank, UPI', blurb: 'How organizations can pay their invoices.' },
    { key: 'invoices', icon: 'ti ti-file-invoice', title: 'Invoice Settings', caption: 'Numbering, renewals', blurb: 'Invoice numbers, renewal invoices and the default trial.' },
    { key: 'tax', icon: 'ti ti-receipt-tax', title: 'Tax & Compliance', caption: 'GST, tax rate', blurb: 'How tax is worked out on every price.' },
    { key: 'notifications', icon: 'ti ti-bell', title: 'Notifications', caption: 'Reminders, grace period', blurb: 'When organizations are reminded, and what grace allows.' },
    { key: 'integrations', icon: 'ti ti-plug-connected', title: 'Integrations', caption: 'Gateway, webhook', blurb: 'The connections payments depend on.' },
];

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

/** "UTC +5:30" for a zone, from the browser's own tables. */
function utcOffset(timeZone: string): string {
    try {
        const name = new Intl.DateTimeFormat('en-US', { timeZone, timeZoneName: 'shortOffset' })
            .formatToParts(new Date())
            .find((part) => part.type === 'timeZoneName')?.value;

        return !name || name === 'GMT' ? 'UTC' : name.replace('GMT', 'UTC');
    } catch {
        return '';
    }
}

/** The financial year today falls in, in the platform's timezone: Apr 2026 – Mar 2027. */
function financialYear(start: number, timeZone: string): string {
    let year = new Date().getFullYear();
    let month = new Date().getMonth() + 1;

    try {
        const parts = new Intl.DateTimeFormat('en-US', { timeZone, year: 'numeric', month: 'numeric' }).formatToParts(new Date());
        year = Number(parts.find((p) => p.type === 'year')?.value ?? year);
        month = Number(parts.find((p) => p.type === 'month')?.value ?? month);
    } catch {
        // An unknown zone: the browser's own date is near enough for a preview.
    }

    const from = month >= start ? year : year - 1;
    const last = ((start + 10) % 12) + 1;

    return `${MONTHS[start - 1].slice(0, 3)} ${from} – ${MONTHS[last - 1].slice(0, 3)} ${start === 1 ? from : from + 1}`;
}

/** A label flush over its control; the hint and the error under it. */
function Field({ label, wide = false, hint, children }: { label: string; wide?: boolean; hint?: string; children: ReactNode }) {
    return (
        <label className={`sxa-field ${wide ? 'is-wide' : ''}`}>
            <span className="sxa-field-label">{label}</span>
            {children}
            {hint && <small className="sxa-field-hint">{hint}</small>}
        </label>
    );
}

/** One thing that is on or off — a payment method, a reminder channel. */
function Toggle({ icon, title, text, on, onChange }: { icon: string; title: string; text: string; on: boolean; onChange: () => void }) {
    return (
        <label className={`sxa-toggle ${on ? 'is-on' : ''}`}>
            <span className="sxa-toggle-icon" aria-hidden="true"><i className={icon} /></span>
            <span className="sxa-toggle-text">
                <b>{title}</b>
                <small>{text}</small>
            </span>
            <span className="form-check form-switch">
                <input type="checkbox" role="switch" className="form-check-input" checked={on} onChange={onChange} />
            </span>
        </label>
    );
}

/**
 * Every billing policy, as a setting — the section list on the left, the
 * form in the middle, and the invoice as these settings will print it on the
 * right. Nothing on this page is decoration: each field changes what the
 * platform does.
 */
export default function BillingSettingsPage() {
    const { data, isLoading, isError, refetch } = useBillingSettings();
    const { data: plans } = usePlans();
    const save = useBillingMutation<Record<string, unknown>>('put', '/settings');
    const [section, setSection] = useState<Section>('general');
    const [form, setForm] = useState<Settings | null>(null);
    const [secrets, setSecrets] = useState({ razorpay_key_id: '', razorpay_key_secret: '', razorpay_webhook_secret: '' });
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [failure, setFailure] = useState<string | null>(null);
    const [copied, setCopied] = useState(false);

    const zones = useMemo(() => {
        let list: string[];

        try {
            list = (Intl as unknown as { supportedValuesOf(k: string): string[] }).supportedValuesOf('timeZone');
        } catch {
            list = ['UTC'];
        }

        return list;
    }, []);

    // Browsers list some zones by their old names (Asia/Calcutta for
    // Asia/Kolkata); the saved one must always be choosable.
    const timezones = useMemo(
        () => Array.from(new Set(['Asia/Kolkata', form?.timezone ?? 'Asia/Kolkata', ...zones])).map((tz) => ({ value: tz, label: `${tz} (${utcOffset(tz)})` })),
        [zones, form?.timezone],
    );

    useEffect(() => {
        if (data) {
            setForm(data.settings);
        }
    }, [data]);

    if (isLoading || !form) {
        return isError ? <ErrorState onRetry={() => void refetch()} /> : <LoadingBlock />;
    }

    const set = <K extends keyof Settings>(key: K, value: Settings[K]) => setForm({ ...form, [key]: value });
    const seller = (key: keyof Settings['seller'], value: string) => set('seller', { ...form.seller, [key]: value || null });
    const days = (text: string) => text.split(',').map((d) => d.trim()).filter(Boolean).map(Number);
    const toggle = (list: string[], value: string) => (list.includes(value) ? list.filter((v) => v !== value) : [...list, value]);
    const error = (name: string) => <FieldError errors={errors} name={name} />;
    const current = SECTIONS.find((s) => s.key === section)!;

    // The number the next invoice will carry, with the prefix as typed.
    const savedPrefix = data?.settings.invoice_prefix ?? '';
    const nextNumber = data?.next_invoice_number?.startsWith(savedPrefix)
        ? form.invoice_prefix + data.next_invoice_number.slice(savedPrefix.length)
        : (data?.next_invoice_number ?? '—');

    async function submit() {
        setErrors({});
        setFailure(null);

        try {
            await save.mutateAsync({ ...form, ...Object.fromEntries(Object.entries(secrets).filter(([, v]) => v.trim() !== '')) });
            setSecrets({ razorpay_key_id: '', razorpay_key_secret: '', razorpay_webhook_secret: '' });
        } catch (failed) {
            const validation = getValidationErrors(failed);
            validation ? setErrors(validation) : setFailure(resolveErrorMessage(failed));
        }
    }

    async function copyWebhook() {
        try {
            await navigator.clipboard.writeText(data?.webhook_url ?? '');
            setCopied(true);
            window.setTimeout(() => setCopied(false), 1500);
        } catch {
            // Clipboard refused (insecure origin): the field is selectable.
        }
    }

    return (
        <>
            <PageHeader
                title="Settings"
                subtitle="Configure billing settings, taxes, payment methods and notifications."
                icon="ti ti-settings"
                actions={<Button loading={save.isPending} icon="ti ti-device-floppy" onClick={() => void submit()}>Save Changes</Button>}
            />

            {failure && <div className="alert alert-danger">{failure}</div>}

            <div className="sxa-settings">
                <Card className="sxa-settings-nav">
                    <nav aria-label="Settings sections">
                        {SECTIONS.map((item) => (
                            <button
                                key={item.key}
                                type="button"
                                className={section === item.key ? 'is-on' : ''}
                                aria-current={section === item.key ? 'page' : undefined}
                                onClick={() => setSection(item.key)}
                            >
                                <span className="sxa-settings-icon" aria-hidden="true"><i className={item.icon} /></span>
                                <span className="sxa-settings-label">
                                    <b>{item.title}</b>
                                    <small>{item.caption}</small>
                                </span>
                            </button>
                        ))}
                    </nav>
                </Card>

                <Card className="sxa-settings-form">
                    <div className="sxa-settings-head">
                        <h6>{current.title} Settings</h6>
                        <p>{current.blurb}</p>
                    </div>

                    {section === 'general' && (
                        <div className="sxa-form">
                            <Field label="Company Name" wide>
                                <input className="form-control" placeholder="Legal name printed on every invoice" value={form.seller.name ?? ''} onChange={(e) => seller('name', e.target.value)} />
                            </Field>
                            <Field label="Address" wide>
                                <textarea className="form-control" rows={2} placeholder="Street, city, state – PIN" value={form.seller.address ?? ''} onChange={(e) => seller('address', e.target.value)} />
                            </Field>
                            <Field label="GST Number">
                                <input className="form-control" placeholder="e.g. 06ABCDE1234F1Z5" value={form.seller.gstin ?? ''} onChange={(e) => seller('gstin', e.target.value.toUpperCase())} />
                                {error('seller.gstin')}
                            </Field>
                            <Field label="Currency">
                                <select className="form-select" value="INR" disabled>
                                    <option value="INR">INR (₹) – Indian Rupee</option>
                                </select>
                            </Field>
                            <Field label="Email">
                                <input type="email" className="form-control" placeholder="billing@company.com" value={form.seller.email ?? ''} onChange={(e) => seller('email', e.target.value)} />
                                {error('seller.email')}
                            </Field>
                            <Field label="Phone">
                                <input className="form-control" placeholder="Billing contact number" value={form.seller.phone ?? ''} onChange={(e) => seller('phone', e.target.value)} />
                                {error('seller.phone')}
                            </Field>
                            <Field label="Timezone">
                                <select className="form-select" value={form.timezone} onChange={(e) => set('timezone', e.target.value)}>
                                    {timezones.map((tz) => <option key={tz.value} value={tz.value}>{tz.label}</option>)}
                                </select>
                                {error('timezone')}
                            </Field>
                            <Field label="Fiscal Year Start">
                                <select className="form-select" value={form.fiscal_year_start} onChange={(e) => set('fiscal_year_start', Number(e.target.value))}>
                                    {MONTHS.map((m, i) => <option key={m} value={i + 1}>{m}</option>)}
                                </select>
                                {error('fiscal_year_start')}
                            </Field>
                        </div>
                    )}

                    {section === 'payments' && (
                        <div className="sxa-form">
                            <div className="sxa-toggles">
                                <Toggle
                                    icon="ti ti-credit-card"
                                    title="Razorpay"
                                    text="Cards, UPI, net banking and wallets — confirmed by Razorpay."
                                    on={form.gateways.includes('razorpay')}
                                    onChange={() => set('gateways', toggle(form.gateways, 'razorpay'))}
                                />
                                <Toggle
                                    icon="ti ti-building-bank"
                                    title="Manual"
                                    text="Bank transfer, UPI or cheque, recorded by an administrator."
                                    on={form.gateways.includes('manual')}
                                    onChange={() => set('gateways', toggle(form.gateways, 'manual'))}
                                />
                                {error('gateways')}
                            </div>
                            {(['razorpay_key_id', 'razorpay_key_secret', 'razorpay_webhook_secret'] as const).map((key) => (
                                <Field key={key} label={{ razorpay_key_id: 'Razorpay Key ID', razorpay_key_secret: 'Razorpay Key Secret', razorpay_webhook_secret: 'Webhook Secret' }[key]} wide={key === 'razorpay_webhook_secret'}>
                                    <input
                                        type={key === 'razorpay_key_id' ? 'text' : 'password'}
                                        autoComplete="off"
                                        className="form-control"
                                        placeholder={data?.secrets[key] ? 'Saved — leave blank to keep it' : 'Not set'}
                                        value={secrets[key]}
                                        onChange={(e) => setSecrets({ ...secrets, [key]: e.target.value })}
                                    />
                                    {error(key)}
                                </Field>
                            ))}
                        </div>
                    )}

                    {section === 'invoices' && (
                        <div className="sxa-form">
                            <Field label="Invoice Number Prefix">
                                <input className="form-control" value={form.invoice_prefix} onChange={(e) => set('invoice_prefix', e.target.value.toUpperCase())} />
                                {error('invoice_prefix')}
                            </Field>
                            <Field label="Renewal Invoice (days before expiry)">
                                <input type="number" min={0} className="form-control" value={form.renewal_invoice_days} onChange={(e) => set('renewal_invoice_days', Number(e.target.value))} />
                                {error('renewal_invoice_days')}
                            </Field>
                            <Field label="Default Trial Plan" wide hint="The plan a new organization starts its trial on when nobody picks one.">
                                <select className="form-select" value={form.default_trial_plan_id ?? ''} onChange={(e) => set('default_trial_plan_id', e.target.value ? Number(e.target.value) : null)}>
                                    <option value="">None — new organizations start on Complimentary</option>
                                    {(plans ?? []).filter((p) => p.status === 'published' && !p.is_system && p.trial_days > 0).map((plan) => (
                                        <option key={plan.id} value={plan.id}>{plan.name} ({plan.trial_days}-day trial)</option>
                                    ))}
                                </select>
                                {error('default_trial_plan_id')}
                            </Field>
                        </div>
                    )}

                    {section === 'tax' && (
                        <div className="sxa-form">
                            <Field label="How Prices Treat Tax" wide>
                                <select className="form-select" value={form.tax_mode} onChange={(e) => set('tax_mode', e.target.value as Settings['tax_mode'])}>
                                    <option value="exclusive">Exclusive — tax added on top of the price</option>
                                    <option value="inclusive">Inclusive — tax inside the price</option>
                                    <option value="none">No tax</option>
                                </select>
                                {error('tax_mode')}
                            </Field>
                            <Field label="Tax Rate (%)">
                                <input type="number" min={0} max={100} step="0.01" className="form-control" value={form.tax_rate_bp / 100} onChange={(e) => set('tax_rate_bp', Math.round(Number(e.target.value) * 100))} />
                                {error('tax_rate_bp')}
                            </Field>
                            <Field label="Tax Label">
                                <input className="form-control" value={form.tax_label} onChange={(e) => set('tax_label', e.target.value)} />
                                {error('tax_label')}
                            </Field>
                            <Field label="GST Registration Number" wide>
                                <input className="form-control" placeholder="e.g. 06ABCDE1234F1Z5" value={form.seller.gstin ?? ''} onChange={(e) => seller('gstin', e.target.value.toUpperCase())} />
                                {error('seller.gstin')}
                            </Field>
                        </div>
                    )}

                    {section === 'notifications' && (
                        <div className="sxa-form">
                            <Field label="During the Grace Period" wide hint="The number of grace days is set on each plan.">
                                <select className="form-select" value={form.grace_access} onChange={(e) => set('grace_access', e.target.value as Settings['grace_access'])}>
                                    <option value="full">Full access, with a warning</option>
                                    <option value="read_only">Read-only — no changes until renewed</option>
                                </select>
                            </Field>
                            <Field label="Remind (days before expiry)">
                                <input className="form-control" placeholder="7, 3, 1, 0" value={form.reminder_days.join(', ')} onChange={(e) => set('reminder_days', days(e.target.value))} />
                                {error('reminder_days')}
                            </Field>
                            <Field label="Remind (days before grace ends)">
                                <input className="form-control" placeholder="1" value={form.grace_reminder_days.join(', ')} onChange={(e) => set('grace_reminder_days', days(e.target.value))} />
                                {error('grace_reminder_days')}
                            </Field>
                            <Field label="Dashboard Warning From (days)">
                                <input type="number" min={0} className="form-control" value={form.warning_days} onChange={(e) => set('warning_days', Number(e.target.value))} />
                                {error('warning_days')}
                            </Field>
                            <div className="sxa-toggles">
                                <Toggle
                                    icon="ti ti-mail"
                                    title="Email reminders"
                                    text="Sent to the organization's owner."
                                    on={form.reminder_channels.includes('email')}
                                    onChange={() => set('reminder_channels', toggle(form.reminder_channels, 'email'))}
                                />
                                <Toggle
                                    icon="ti ti-brand-whatsapp"
                                    title="WhatsApp reminders"
                                    text="Needs an approved platform template."
                                    on={form.reminder_channels.includes('whatsapp')}
                                    onChange={() => set('reminder_channels', toggle(form.reminder_channels, 'whatsapp'))}
                                />
                                {error('reminder_channels')}
                            </div>
                        </div>
                    )}

                    {section === 'integrations' && (
                        <div className="sxa-form">
                            <div className="sxa-toggles">
                                <div className="sxa-toggle is-static">
                                    <span className="sxa-toggle-icon" aria-hidden="true"><i className="ti ti-credit-card" /></span>
                                    <span className="sxa-toggle-text">
                                        <b>Razorpay</b>
                                        <small>{data?.online_ready ? 'Ready to take payments.' : 'Keys missing, or switched off under Payment Methods.'}</small>
                                    </span>
                                    <StatusChip status={data?.online_ready ? 'active' : 'draft'} label={data?.online_ready ? 'Connected' : 'Not set'} />
                                </div>
                                <div className="sxa-toggle is-static">
                                    <span className="sxa-toggle-icon" aria-hidden="true"><i className="ti ti-webhook" /></span>
                                    <span className="sxa-toggle-text">
                                        <b>Payment webhook</b>
                                        <small>Confirms a payment even when the browser is closed.</small>
                                    </span>
                                    <StatusChip status={data?.secrets.razorpay_webhook_secret ? 'active' : 'draft'} label={data?.secrets.razorpay_webhook_secret ? 'Secret saved' : 'Not set'} />
                                </div>
                            </div>
                            <Field label="Webhook URL — add it in the Razorpay dashboard" wide hint="Events: payment.captured, order.paid, payment.failed.">
                                <span className="sxa-copy">
                                    <input className="form-control" readOnly value={data?.webhook_url ?? ''} onFocus={(e) => e.target.select()} />
                                    <Button type="button" variant="light" icon={copied ? 'ti ti-check' : 'ti ti-copy'} onClick={() => void copyWebhook()}>
                                        {copied ? 'Copied' : 'Copy'}
                                    </Button>
                                </span>
                            </Field>
                        </div>
                    )}
                </Card>

                <Card className="sxa-settings-preview">
                    <h6 className="sxa-settings-title">Invoice Preview</h6>
                    <div className="sxa-invoice">
                        <div className="sxa-invoice-head">
                            <span className="sxa-invoice-mark" aria-hidden="true"><i className="ti ti-building-hospital" /></span>
                            <div className="sxa-invoice-seller">
                                <b className={form.seller.name ? '' : 'is-empty'}>{form.seller.name || 'Company name'}</b>
                                <small className={form.seller.address ? '' : 'is-empty'}>{form.seller.address || 'Address'}</small>
                                <small className={form.seller.gstin ? '' : 'is-empty'}>GSTIN: {form.seller.gstin || 'not set'}</small>
                            </div>
                        </div>

                        <div className="sxa-invoice-doc">
                            <div className="sxa-invoice-band">INVOICE</div>
                            <dl>
                                <div><dt>Invoice #</dt><dd>{nextNumber}</dd></div>
                                <div><dt>Date</dt><dd>{formatShortDay(new Date().toISOString(), form.timezone)}</dd></div>
                                <div><dt>Due Date</dt><dd>{formatShortDay(new Date(Date.now() + 7 * 864e5).toISOString(), form.timezone)}</dd></div>
                                <div><dt>Tax</dt><dd>{form.tax_mode === 'none' ? 'None' : `${form.tax_label} ${form.tax_rate_bp / 100}% (${form.tax_mode})`}</dd></div>
                                <div><dt>Financial Year</dt><dd>{financialYear(form.fiscal_year_start, form.timezone)}</dd></div>
                            </dl>
                        </div>

                        {!form.seller.name && (
                            <p className="sxa-invoice-note">
                                <i className="ti ti-info-circle" aria-hidden="true" />
                                Add the company name, address and GST number under General — they head every invoice.
                            </p>
                        )}
                    </div>
                </Card>
            </div>
        </>
    );
}
