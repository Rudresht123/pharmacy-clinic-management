import { useEffect } from 'react';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import { LoadingBlock } from '@/shared/components/ui/Feedback';
import { FormError, SelectField, SwitchField, TextField } from '@/shared/components/form/Fields';
import { useApiForm } from '@/shared/components/form/useApiForm';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { usePharmacySettings, useSavePharmacySettings } from '../api';
import { PAYMENT_METHOD_LABELS, type PharmacySettings } from '../types';

type Values = Record<string, unknown>;

/** How far ahead a batch is worth warning about. */
const EXPIRY_CHOICES = [30, 60, 90, 180].map((days) => ({
    value: days,
    label: `${days} days`,
}));

/**
 * How the organisation bills, prices and warns.
 *
 * One record for the whole organisation — a chain bills the same way at
 * every counter — so there is no store picker here. Anything that genuinely
 * differs by counter (its licence, its name on the bill) is on the store.
 *
 * Every value a sale depends on is copied onto the sale when it is made, so
 * changing something here never rewrites a bill that is already printed.
 */
export default function PharmacySettingsPage() {
    const { can } = useTenantAuth();
    const editable = can('pharmacy.stores');

    const { data: settings, isLoading } = usePharmacySettings();
    const save = useSavePharmacySettings();

    const {
        register,
        control,
        handleSubmit,
        reset,
        submit,
        watch,
        formState: { errors, isSubmitting, isDirty },
    } = useApiForm<Values>({ defaultValues: {} });

    useEffect(() => {
        if (!settings) {
            return;
        }

        reset({ ...settings });
    }, [settings, reset]);

    const onSubmit = handleSubmit(async (values) => {
        const payload: Partial<PharmacySettings> = {
            invoice_prefix: String(values.invoice_prefix ?? '').trim(),
            round_off_enabled: Boolean(values.round_off_enabled),
            price_basis: values.price_basis as PharmacySettings['price_basis'],
            prices_include_tax: Boolean(values.prices_include_tax),
            expiry_warning_days: Number(values.expiry_warning_days),
            allow_walk_in: Boolean(values.allow_walk_in),
            credit_sales_enabled: Boolean(values.credit_sales_enabled),
            require_prescription: Boolean(values.require_prescription),
            default_payment_method: String(values.default_payment_method),
        };

        const result = await submit(values, () => save.mutateAsync(payload));

        if (result) {
            reset({ ...result });
        }
    });

    if (isLoading) {
        return <LoadingBlock label="Loading settings…" />;
    }

    const inclusive = Boolean(watch('prices_include_tax'));

    return (
        <>
            <PageHeader
                title="Pharmacy Settings"
                icon="ti ti-adjustments"
                tone="teal"
                crumbs={[{ label: 'Pharmacy' }, { label: 'Settings' }]}
            />

            <form onSubmit={onSubmit} noValidate>
                <FormError message={errors.root?.message} />

                {!editable && (
                    <p className="form-legend">
                        These are your organisation&rsquo;s settings. Ask whoever sets up the pharmacy to
                        change them.
                    </p>
                )}

                <fieldset disabled={!editable} className="border-0 p-0 m-0">
                    <div className="row g-3">
                        <div className="col-lg-6">
                            <Card
                                title="Billing"
                                icon="ti ti-receipt"
                                description="How a bill is numbered, priced and rounded."
                            >
                                <TextField
                                    name="invoice_prefix"
                                    label="Invoice prefix"
                                    register={register}
                                    errors={errors}
                                    placeholder="INV"
                                    hint="Bills are numbered INV-00001 upwards, and never repeat."
                                />

                                <SelectField
                                    name="price_basis"
                                    label="Charge at"
                                    control={control}
                                    errors={errors}
                                    options={[
                                        { value: 'mrp', label: 'MRP — the price printed on the pack' },
                                        { value: 'selling', label: "Selling price — the store's own" },
                                    ]}
                                    hint="Both are recorded on every batch; this is the one the counter charges."
                                />

                                <SwitchField
                                    name="prices_include_tax"
                                    label="Prices include GST"
                                    register={register}
                                    errors={errors}
                                    hint={
                                        inclusive
                                            ? 'Indian MRP includes GST, so the bill splits the tax out of the price.'
                                            : 'GST will be added on top of the price on every bill.'
                                    }
                                />

                                <SwitchField
                                    name="round_off_enabled"
                                    label="Round the bill total"
                                    register={register}
                                    errors={errors}
                                    hint="Rounds to the nearest rupee and records the difference on the bill."
                                />

                                <SelectField
                                    name="default_payment_method"
                                    label="Default payment method"
                                    control={control}
                                    errors={errors}
                                    options={Object.entries(PAYMENT_METHOD_LABELS).map(([value, label]) => ({
                                        value,
                                        label,
                                    }))}
                                />
                            </Card>
                        </div>

                        <div className="col-lg-6">
                            <Card
                                title="The Counter"
                                icon="ti ti-cash-register"
                                description="Who may be sold to, and on what terms."
                            >
                                <SwitchField
                                    name="allow_walk_in"
                                    label="Sell to walk-in customers"
                                    register={register}
                                    errors={errors}
                                    hint="Off means every sale must name a registered customer."
                                />

                                <SwitchField
                                    name="credit_sales_enabled"
                                    label="Allow credit sales"
                                    register={register}
                                    errors={errors}
                                    hint="A credit sale is owed by the customer and tracked on their ledger."
                                />

                                <SwitchField
                                    name="require_prescription"
                                    label="Refuse prescription-only medicines without one"
                                    register={register}
                                    errors={errors}
                                    hint="Applies to the medicines your catalogue marks prescription-only."
                                />
                            </Card>

                            <Card
                                title="Stock Warnings"
                                icon="ti ti-alert-triangle"
                                description="When a batch starts being flagged."
                                className="mt-3"
                            >
                                <SelectField
                                    name="expiry_warning_days"
                                    label="Warn about expiry within"
                                    control={control}
                                    errors={errors}
                                    options={EXPIRY_CHOICES}
                                    hint="An expired batch is never sold, whatever this says."
                                />
                            </Card>
                        </div>
                    </div>
                </fieldset>

                {editable && (
                    <div className="form-actions">
                        <span className="form-actions-note">
                            {settings?.updated_at
                                ? `Last saved by ${settings.updated_by_name ?? 'someone'}.`
                                : 'These apply to every store in your organisation.'}
                        </span>

                        <Button
                            type="submit"
                            loading={isSubmitting}
                            disabled={!isDirty}
                            icon="ti ti-device-floppy"
                        >
                            Save settings
                        </Button>
                    </div>
                )}
            </form>
        </>
    );
}
