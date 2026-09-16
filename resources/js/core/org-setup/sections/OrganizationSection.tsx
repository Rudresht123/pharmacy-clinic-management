import { useEffect, useMemo } from 'react';
import { Button } from '@/shared/components/ui/Button';
import { FormError, TextareaField, TextField } from '@/shared/components/form/Fields';
import { useApiForm } from '@/shared/components/form/useApiForm';
import { usePincodeAutofill } from '@/shared/geo/usePincodeAutofill';
import { useSaveSetupOrganization } from '../api';
import { SectionShell, SetupPanel, type SectionProps } from '../components/SectionShell';
import type { SetupOrganization } from '../types';

type Values = {
    organization_name: string;
    legal_name: string;
    contact_person_name: string;
    phone_number: string;
    gstin: string;
    drug_license_no: string;
    website_url: string;
    address: string;
    postal_code: string;
    city: string;
    state_province: string;
};

function toValues(organization: SetupOrganization): Values {
    return {
        organization_name: organization.name ?? '',
        legal_name: organization.legal_name ?? '',
        contact_person_name: organization.contact_person_name ?? '',
        phone_number: organization.phone_number ?? '',
        gstin: organization.gstin ?? '',
        drug_license_no: organization.drug_license_no ?? '',
        website_url: organization.website_url ?? '',
        address: organization.address ?? '',
        postal_code: organization.postal_code ?? '',
        city: organization.city ?? '',
        state_province: organization.state_province ?? '',
    };
}

/** A value the platform owns: shown like a field, never edited here. */
function Fixed({ id, label, value, hint }: { id: string; label: string; value: string; hint?: string }) {
    return (
        <div className="mb-3">
            <label className="form-label" htmlFor={id}>
                {label}
            </label>
            <input id={id} type="text" className="form-control" value={value} readOnly disabled />
            {hint && <small className="text-muted d-block mt-1">{hint}</small>}
        </div>
    );
}

/**
 * The organisation's own record: who it is, how to reach it, where it is.
 *
 * The type and email are shown, not edited — the platform set them, and the
 * email is the owner's sign-in. A postal code fills the city and state in,
 * without overwriting what somebody typed.
 */
export function OrganizationSection({ status, step, onDirty, nav }: SectionProps) {
    const save = useSaveSetupOrganization();
    const initial = useMemo(() => toValues(status.organization), [status.organization]);

    const {
        register,
        control,
        setValue,
        getValues,
        handleSubmit,
        reset,
        submit,
        formState: { errors, isSubmitting, isDirty },
    } = useApiForm<Values>({ defaultValues: initial });

    const autofill = usePincodeAutofill({
        control,
        setValue,
        getValues,
        pincode: 'postal_code',
        fills: { district: 'city', state: 'state_province' },
    });

    useEffect(() => onDirty(isDirty), [isDirty, onDirty]);

    // The server's copy wins when it changes, unless somebody is typing.
    useEffect(() => {
        if (!isDirty) reset(initial);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [initial]);

    async function persist(values: Values, then?: () => void) {
        const result = await submit(values, (sent) => save.mutateAsync(sent));

        if (result) {
            reset(values);
            then?.();
        }
    }

    const onSave = handleSubmit((values) => persist(values));
    const onSaveNext = handleSubmit((values) =>
        persist(values, () => nav.next && nav.go(nav.next, true)),
    );

    const phoneError = errors.phone_number?.message;

    return (
        <SectionShell
            step={step}
            nav={nav}
            actions={
                <Button variant="light" icon="ti ti-device-floppy" loading={isSubmitting} onClick={() => void onSave()}>
                    Save
                </Button>
            }
            primary={
                <Button loading={isSubmitting} onClick={() => void onSaveNext()}>
                    Save &amp; Continue
                    <i className="ti ti-arrow-right ms-1" aria-hidden="true" />
                </Button>
            }
        >
            <form onSubmit={onSave} noValidate className="su-form">
                <FormError message={errors.root?.message} />

                <SetupPanel icon="ti ti-building" title="Basic Details">
                    <div className="row">
                        <div className="col-md-6">
                            <TextField
                                name="organization_name"
                                label="Organisation Name"
                                required
                                register={register}
                                errors={errors}
                            />
                        </div>
                        <div className="col-md-6">
                            <Fixed
                                id="su-type"
                                label="Organisation Type"
                                value={status.organization.type ?? '—'}
                            />
                        </div>

                        <div className="col-md-6">
                            <TextField
                                name="legal_name"
                                label="Registered (Legal) Name"
                                register={register}
                                errors={errors}
                                placeholder="Sunrise Healthcare Pvt. Ltd."
                            />
                        </div>
                        <div className="col-md-6">
                            <TextField
                                name="gstin"
                                label="Tax ID / GST Number"
                                register={register}
                                errors={errors}
                                placeholder="22AAAAA0000A1Z5"
                            />
                        </div>

                        <div className="col-md-6">
                            <TextField
                                name="drug_license_no"
                                label="Registration / Drug Licence Number"
                                register={register}
                                errors={errors}
                                placeholder="REG123456"
                            />
                        </div>
                        <div className="col-md-6">
                            <TextField
                                name="website_url"
                                label="Website"
                                type="url"
                                register={register}
                                errors={errors}
                                placeholder="https://www.example.com"
                            />
                        </div>

                        <div className="col-md-6">
                            <TextField
                                name="contact_person_name"
                                label="Contact Person"
                                required
                                register={register}
                                errors={errors}
                            />
                        </div>
                        <div className="col-md-6">
                            <Fixed
                                id="su-email"
                                label="Email"
                                value={status.organization.email}
                                hint="The owner signs in with this."
                            />
                        </div>

                        <div className="col-md-6">
                            <div className="mb-3">
                                <label className="form-label" htmlFor="phone_number">
                                    Phone Number <span className="text-danger ms-1">*</span>
                                </label>
                                <div className={`su-phone${phoneError ? ' is-invalid' : ''}`}>
                                    <span className="su-phone-code" aria-hidden="true">
                                        🇮🇳 +91
                                    </span>
                                    <input
                                        id="phone_number"
                                        type="tel"
                                        className={`form-control${phoneError ? ' is-invalid' : ''}`}
                                        placeholder="98765 43210"
                                        autoComplete="tel-national"
                                        {...register('phone_number')}
                                    />
                                </div>
                                {phoneError && (
                                    <div className="invalid-feedback d-block">{String(phoneError)}</div>
                                )}
                            </div>
                        </div>
                    </div>
                </SetupPanel>

                <SetupPanel icon="ti ti-map-pin" title="Address">
                    <TextareaField
                        name="address"
                        label="Street Address"
                        required
                        rows={2}
                        register={register}
                        errors={errors}
                        placeholder="123, Green Park, Near City Hospital"
                    />

                    <div className="row">
                        <div className="col-md-4">
                            <TextField
                                name="postal_code"
                                label="Postal Code"
                                type="tel"
                                required
                                register={register}
                                errors={errors}
                                autoComplete="postal-code"
                                placeholder="110016"
                                hint={
                                    autofill.isLoading
                                        ? 'Looking the code up…'
                                        : autofill.place
                                          ? 'City and state filled in from the code.'
                                          : 'A 6-digit PIN code fills the city and state.'
                                }
                            />
                        </div>
                        <div className="col-md-4">
                            <TextField name="city" label="City" required register={register} errors={errors} />
                        </div>
                        <div className="col-md-4">
                            <TextField
                                name="state_province"
                                label="State"
                                required
                                register={register}
                                errors={errors}
                            />
                        </div>
                    </div>
                </SetupPanel>
            </form>
        </SectionShell>
    );
}
