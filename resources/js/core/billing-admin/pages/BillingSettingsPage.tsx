import { useEffect, useMemo, useState } from 'react';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import { ErrorState, LoadingBlock } from '@/shared/components/ui/Feedback';
import { getValidationErrors, resolveErrorMessage } from '@/shared/api/http';
import { formatDay } from '@/core/subscription/money';
import { useBillingMutation, useBillingSettings, usePlans, type BillingSettings } from '../api';
import { FieldError } from '../components/Common';

type Section = 'general' | 'payments' | 'invoices' | 'tax' | 'notifications' | 'integrations';
type Settings = BillingSettings['settings'];

const SECTIONS: Array<{ key: Section; icon: string; title: string; caption: string }> = [
    { key: 'general', icon: 'ti ti-building', title: 'General', caption: 'Billing details, timezone, financial year' },
    { key: 'payments', icon: 'ti ti-credit-card', title: 'Payment Methods', caption: 'Razorpay, bank transfer, UPI' },
    { key: 'invoices', icon: 'ti ti-file-invoice', title: 'Invoice Settings', caption: 'Numbering, renewal invoices, trials' },
    { key: 'tax', icon: 'ti ti-receipt-tax', title: 'Tax & Compliance', caption: 'GST, tax rate, registration' },
    { key: 'notifications', icon: 'ti ti-bell', title: 'Notifications', caption: 'Expiry reminders, grace period' },
    { key: 'integrations', icon: 'ti ti-plug-connected', title: 'Integrations', caption: 'Payment gateway webhook' },
];

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

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
    const timezones = useMemo(() => {
        try {
            return (Intl as unknown as { supportedValuesOf(k: string): string[] }).supportedValuesOf('timeZone');
        } catch {
            return ['Asia/Kolkata', 'UTC'];
        }
    }, []);

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

    async function submit() {
        setErrors({});
        setFailure(null);

        try {
            await save.mutateAsync({ ...form, ...Object.fromEntries(Object.entries(secrets).filter(([, v]) => v.trim() !== '')) });
            setSecrets({ razorpay_key_id: '', razorpay_key_secret: '', razorpay_webhook_secret: '' });
        } catch (error) {
            const validation = getValidationErrors(error);
            validation ? setErrors(validation) : setFailure(resolveErrorMessage(error));
        }
    }

    const field = (label: string, input: React.ReactNode, error?: string, hint?: string) => (
        <div className="mb-3">
            <label className="form-label">{label}</label>
            {input}
            {error && <FieldError errors={errors} name={error} />}
            {hint && <small className="text-muted d-block mt-1">{hint}</small>}
        </div>
    );

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
                    <nav>
                        {SECTIONS.map((item) => (
                            <button key={item.key} type="button" className={section === item.key ? 'is-on' : ''} onClick={() => setSection(item.key)}>
                                <i className={item.icon} aria-hidden="true" />
                                <span>
                                    <b>{item.title}</b>
                                    <small>{item.caption}</small>
                                </span>
                            </button>
                        ))}
                    </nav>
                </Card>

                <Card className="sxa-settings-form">
                    <h6 className="sxa-settings-title">{SECTIONS.find((s) => s.key === section)?.title} Settings</h6>

                    {section === 'general' && (
                        <>
                            {field('Company Name', <input className="form-control" value={form.seller.name ?? ''} onChange={(e) => seller('name', e.target.value)} />, undefined, 'The legal name printed on every invoice.')}
                            {field('Address', <textarea className="form-control" rows={3} value={form.seller.address ?? ''} onChange={(e) => seller('address', e.target.value)} />)}
                            <div className="row g-3">
                                <div className="col-md-6">{field('GST Number', <input className="form-control" value={form.seller.gstin ?? ''} onChange={(e) => seller('gstin', e.target.value.toUpperCase())} />, 'seller.gstin')}</div>
                                <div className="col-md-6">{field('Currency', <input className="form-control" value="INR — Indian Rupee" disabled />)}</div>
                                <div className="col-md-6">{field('Email', <input className="form-control" value={form.seller.email ?? ''} onChange={(e) => seller('email', e.target.value)} />)}</div>
                                <div className="col-md-6">{field('Phone', <input className="form-control" value={form.seller.phone ?? ''} onChange={(e) => seller('phone', e.target.value)} />)}</div>
                                <div className="col-md-6">
                                    {field('Timezone', (
                                        <select className="form-select" value={form.timezone} onChange={(e) => set('timezone', e.target.value)}>
                                            {timezones.map((tz) => <option key={tz} value={tz}>{tz}</option>)}
                                        </select>
                                    ), 'timezone')}
                                </div>
                                <div className="col-md-6">
                                    {field('Fiscal Year Start', (
                                        <select className="form-select" value={form.fiscal_year_start} onChange={(e) => set('fiscal_year_start', Number(e.target.value))}>
                                            {MONTHS.map((m, i) => <option key={m} value={i + 1}>{m}</option>)}
                                        </select>
                                    ), 'fiscal_year_start', 'Invoice numbers start a new series each financial year.')}
                                </div>
                            </div>
                        </>
                    )}

                    {section === 'payments' && (
                        <>
                            <div className="sxa-checks mb-3">
                                <label><input type="checkbox" className="form-check-input" checked={form.gateways.includes('razorpay')} onChange={() => set('gateways', toggle(form.gateways, 'razorpay'))} /> Razorpay — cards, UPI, net banking, wallets</label>
                                <label><input type="checkbox" className="form-check-input" checked={form.gateways.includes('manual')} onChange={() => set('gateways', toggle(form.gateways, 'manual'))} /> Manual — bank transfer, UPI, cheque (recorded by an administrator)</label>
                            </div>
                            <FieldError errors={errors} name="gateways" />
                            {(['razorpay_key_id', 'razorpay_key_secret', 'razorpay_webhook_secret'] as const).map((key) =>
                                field(
                                    { razorpay_key_id: 'Razorpay Key ID', razorpay_key_secret: 'Razorpay Key Secret', razorpay_webhook_secret: 'Webhook Secret' }[key],
                                    <input
                                        type={key === 'razorpay_key_id' ? 'text' : 'password'}
                                        autoComplete="off"
                                        className="form-control"
                                        placeholder={data?.secrets[key] ? 'Saved — leave blank to keep it' : 'Not set'}
                                        value={secrets[key]}
                                        onChange={(e) => setSecrets({ ...secrets, [key]: e.target.value })}
                                    />,
                                ),
                            )}
                        </>
                    )}

                    {section === 'invoices' && (
                        <>
                            {field('Invoice Number Prefix', <input className="form-control" value={form.invoice_prefix} onChange={(e) => set('invoice_prefix', e.target.value.toUpperCase())} />, 'invoice_prefix', `Next invoice: ${data?.next_invoice_number ?? '—'}`)}
                            {field('Issue the renewal invoice (days before expiry)', <input type="number" min={0} className="form-control" value={form.renewal_invoice_days} onChange={(e) => set('renewal_invoice_days', Number(e.target.value))} />, 'renewal_invoice_days')}
                            {field('Default trial plan for new organizations', (
                                <select className="form-select" value={form.default_trial_plan_id ?? ''} onChange={(e) => set('default_trial_plan_id', e.target.value ? Number(e.target.value) : null)}>
                                    <option value="">None — new organizations start on Complimentary</option>
                                    {(plans ?? []).filter((p) => p.status === 'published' && !p.is_system && p.trial_days > 0).map((plan) => (
                                        <option key={plan.id} value={plan.id}>{plan.name} ({plan.trial_days}-day trial)</option>
                                    ))}
                                </select>
                            ), 'default_trial_plan_id')}
                        </>
                    )}

                    {section === 'tax' && (
                        <>
                            {field('How prices treat tax', (
                                <select className="form-select" value={form.tax_mode} onChange={(e) => set('tax_mode', e.target.value as Settings['tax_mode'])}>
                                    <option value="exclusive">Exclusive — tax added on top of the price</option>
                                    <option value="inclusive">Inclusive — tax inside the price</option>
                                    <option value="none">No tax</option>
                                </select>
                            ), 'tax_mode')}
                            <div className="row g-3">
                                <div className="col-md-6">{field('Tax Rate (%)', <input type="number" min={0} max={100} step="0.01" className="form-control" value={form.tax_rate_bp / 100} onChange={(e) => set('tax_rate_bp', Math.round(Number(e.target.value) * 100))} />, 'tax_rate_bp')}</div>
                                <div className="col-md-6">{field('Tax Label', <input className="form-control" value={form.tax_label} onChange={(e) => set('tax_label', e.target.value)} />)}</div>
                            </div>
                            {field('GST Registration Number', <input className="form-control" value={form.seller.gstin ?? ''} onChange={(e) => seller('gstin', e.target.value.toUpperCase())} />, 'seller.gstin')}
                        </>
                    )}

                    {section === 'notifications' && (
                        <>
                            {field('During the grace period', (
                                <select className="form-select" value={form.grace_access} onChange={(e) => set('grace_access', e.target.value as Settings['grace_access'])}>
                                    <option value="full">Full access, with a warning</option>
                                    <option value="read_only">Read-only — no changes until renewed</option>
                                </select>
                            ), undefined, 'The number of grace days is set on each plan.')}
                            <div className="row g-3">
                                <div className="col-md-6">{field('Remind, days before expiry', <input className="form-control" value={form.reminder_days.join(', ')} onChange={(e) => set('reminder_days', days(e.target.value))} />, 'reminder_days', '0 is the day it ends.')}</div>
                                <div className="col-md-6">{field('Remind, days before grace ends', <input className="form-control" value={form.grace_reminder_days.join(', ')} onChange={(e) => set('grace_reminder_days', days(e.target.value))} />, 'grace_reminder_days')}</div>
                                <div className="col-md-6">{field('Dashboard warning from (days)', <input type="number" min={0} className="form-control" value={form.warning_days} onChange={(e) => set('warning_days', Number(e.target.value))} />, 'warning_days')}</div>
                            </div>
                            <div className="sxa-checks">
                                <span className="text-muted">Send reminders by:</span>
                                {['email', 'whatsapp'].map((channel) => (
                                    <label key={channel}>
                                        <input type="checkbox" className="form-check-input" checked={form.reminder_channels.includes(channel)} onChange={() => set('reminder_channels', toggle(form.reminder_channels, channel))} />
                                        {channel === 'email' ? 'Email' : 'WhatsApp (needs an approved platform template)'}
                                    </label>
                                ))}
                            </div>
                        </>
                    )}

                    {section === 'integrations' && (
                        <>
                            <ul className="sx-list mb-3">
                                <li className={data?.online_ready ? '' : 'is-off'}>
                                    <i className="ti ti-credit-card" aria-hidden="true" />
                                    <span>Razorpay</span>
                                    <small>{data?.online_ready ? 'Ready to take payments' : 'Keys missing or switched off'}</small>
                                </li>
                                <li className={data?.secrets.razorpay_webhook_secret ? '' : 'is-off'}>
                                    <i className="ti ti-webhook" aria-hidden="true" />
                                    <span>Payment webhook</span>
                                    <small>{data?.secrets.razorpay_webhook_secret ? 'Secret saved' : 'Secret not set'}</small>
                                </li>
                            </ul>
                            {field('Webhook URL — add it in the Razorpay dashboard', <input className="form-control" readOnly value={data?.webhook_url ?? ''} onFocus={(e) => e.target.select()} />, undefined, 'Events: payment.captured, order.paid, payment.failed.')}
                        </>
                    )}
                </Card>

                <Card className="sxa-settings-preview">
                    <h6 className="sxa-settings-title">Invoice Preview</h6>
                    <div className="sxa-invoice">
                        <div className="sxa-invoice-head">
                            <span className="sxa-invoice-mark"><i className="ti ti-building-hospital" aria-hidden="true" /></span>
                            <div>
                                <b>{form.seller.name || 'Your company name'}</b>
                                <small>{form.seller.address || 'Address'}</small>
                                {form.seller.gstin && <small>GSTIN: {form.seller.gstin}</small>}
                            </div>
                        </div>
                        <div className="sxa-invoice-band">INVOICE</div>
                        <dl>
                            <div><dt>Invoice No</dt><dd>{data?.next_invoice_number}</dd></div>
                            <div><dt>Date</dt><dd>{formatDay(new Date().toISOString(), form.timezone)}</dd></div>
                            <div><dt>Due</dt><dd>{formatDay(new Date(Date.now() + 7 * 864e5).toISOString(), form.timezone)}</dd></div>
                            <div><dt>Tax</dt><dd>{form.tax_mode === 'none' ? 'None' : `${form.tax_label} ${form.tax_rate_bp / 100}% ${form.tax_mode}`}</dd></div>
                        </dl>
                        <small className="text-muted">How the next invoice will be headed, from these settings.</small>
                    </div>
                </Card>
            </div>
        </>
    );
}
