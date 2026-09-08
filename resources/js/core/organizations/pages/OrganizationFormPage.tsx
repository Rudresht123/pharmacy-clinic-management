import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import { LoadingBlock } from '@/shared/components/ui/Feedback';
import {
    FileField,
    FormError,
    SelectField,
    SwitchField,
    TextField,
    TextareaField,
} from '@/shared/components/form/Fields';
import { useApiForm } from '@/shared/components/form/useApiForm';
import { toFormData } from '@/shared/api/resource';
import { organizationsHooks } from '../api';
import { organizationTypesHooks } from '@/core/organization-types/api';
import type { OrganizationFormValues } from '../types';

const EMPTY: OrganizationFormValues = {
    organization_name: '',
    organization_code: '',
    organization_type_id: '',
    subdomain: '',
    contact_person_name: '',
    email: '',
    phone_number: '',
    address: '',
    is_active: true,

    // §5: nullable at creation, required before the tenant goes live.
    legal_name: '',
    gstin: '',
    drug_license_no: '',
};

export default function OrganizationFormPage() {
    // §5: organizations are addressed by ULID, so this stays a string — a
    // Number() around it would produce NaN and fetch nothing.
    const { uuid } = useParams();
    const navigate = useNavigate();
    const isEdit = Boolean(uuid);

    const { data: organization, isLoading } = organizationsHooks.useDetail(uuid);
    // The select needs every active type, not one page of them.
    const { data: types = [], isLoading: typesLoading } = organizationTypesHooks.useList({
        all: 1,
        active_only: 1,
    });

    /*
     * Null while idle, 0–100 during the request. The logo makes this a
     * multipart upload, and provisioning keeps the connection open for a few
     * seconds afterwards, so a plain spinner would leave the user guessing
     * whether anything was happening.
     */
    const [progress, setProgress] = useState<number | null>(null);

    const create = organizationsHooks.useCreate({ onUploadProgress: setProgress });
    const update = organizationsHooks.useUpdate({ onUploadProgress: setProgress });

    const {
        register,
        control,
        handleSubmit,
        reset,
        submit,
        formState: { errors, isSubmitting },
    } = useApiForm<OrganizationFormValues>({ defaultValues: EMPTY });

    // Populate once the record arrives.
    useEffect(() => {
        if (organization) {
            reset({
                organization_name: organization.organization_name,
                organization_code: organization.organization_code,
                organization_type_id: String(organization.organization_type_id ?? ''),
                subdomain: organization.subdomain,
                contact_person_name: organization.contact_person_name ?? '',
                email: organization.email ?? '',
                phone_number: organization.phone_number ?? '',
                address: organization.address ?? '',
                is_active: organization.is_active,
                legal_name: organization.legal_name ?? '',
                gstin: organization.gstin ?? '',
                drug_license_no: organization.drug_license_no ?? '',
            });
        }
    }, [organization, reset]);

    const onSubmit = handleSubmit(async (values) => {
        const { profile_image, ...rest } = values;

        const payload = toFormData({
            ...rest,
            profile_image: profile_image?.[0],
        });

        setProgress(0);

        try {
            const result = await submit(values, async () =>
                isEdit && uuid
                    ? update.mutateAsync({ id: uuid, payload })
                    : create.mutateAsync(payload),
            );

            if (result) {
                navigate('/organizations');
            }
        } finally {
            // Cleared either way — a validation failure must not leave the
            // field stuck at "Processing".
            setProgress(null);
        }
    });

    if (isEdit && isLoading) {
        return <LoadingBlock label="Loading organization…" />;
    }

    return (
        <>
            <PageHeader
                title={isEdit ? 'Edit Organization' : 'Create Organization'}
                icon={isEdit ? 'ti ti-edit' : 'ti ti-building-plus'}
                tone={isEdit ? 'amber' : 'emerald'}
                crumbs={[
                    { label: 'Global Settings' },
                    { label: 'Organizations', to: '/organizations' },
                    { label: isEdit ? 'Edit' : 'Create' },
                ]}
            />

            <form onSubmit={onSubmit} noValidate>
                <FormError message={errors.root?.message} />

                <p className="form-legend">
                    <span className="req">*</span> Required. Everything else can be filled in later.
                </p>

                <div className="row g-3">
                    <div className="col-lg-8 form-column">
                        <Card
                            title="Organization Information"
                            icon="ti ti-building-store"
                            description="How the pharmacy is identified across the platform."
                        >
                            <TextField
                                name="organization_name"
                                label="Organization Name"
                                required
                                register={register}
                                errors={errors}
                                placeholder="Apollo Pharmacy"
                            />

                            <div className="row">
                                <div className="col-md-6">
                                    <TextField
                                        name="organization_code"
                                        label="Organization Code"
                                        required
                                        register={register}
                                        errors={errors}
                                        placeholder="APL1234"
                                    />
                                </div>

                                <div className="col-md-6">
                                    <SelectField
                                        name="organization_type_id"
                                        label="Organization Type"
                                        required
                                        control={control}
                                        errors={errors}
                                        loading={typesLoading}
                                        placeholder="Choose a type…"
                                        options={types.map((type) => ({
                                            value: type.id,
                                            label: type.name,
                                        }))}
                                    />
                                </div>
                            </div>
                        </Card>

                        <Card
                            title="Contact Information"
                            icon="ti ti-address-book"
                            description="Who we reach out to, and where the setup link is sent."
                        >
                            <div className="row">
                                <div className="col-md-6">
                                    <TextField
                                        name="contact_person_name"
                                        label="Contact Person"
                                        register={register}
                                        errors={errors}
                                        placeholder="Ravi Sharma"
                                    />
                                </div>

                                <div className="col-md-6">
                                    <TextField
                                        name="phone_number"
                                        label="Phone Number"
                                        register={register}
                                        errors={errors}
                                        placeholder="9876543210"
                                        hint="10 to 15 digits, no spaces."
                                    />
                                </div>
                            </div>

                            <TextField
                                name="email"
                                label="Email Address"
                                type="email"
                                required
                                register={register}
                                errors={errors}
                                placeholder="owner@apollopharmacy.in"
                                hint={
                                    isEdit
                                        ? undefined
                                        : 'The account setup link is sent to this address.'
                                }
                            />

                            <TextareaField
                                name="address"
                                label="Address"
                                register={register}
                                errors={errors}
                                rows={3}
                                placeholder="Shop 14, MG Road, Bengaluru 560001"
                            />
                        </Card>

                        {/* §5: nullable at creation, required before the
                            organization is allowed to go live. */}
                        <Card
                            title="Licensing"
                            icon="ti ti-license"
                            description="Optional now — required before the pharmacy can go live."
                        >
                            <TextField
                                name="legal_name"
                                label="Legal Name"
                                register={register}
                                errors={errors}
                                placeholder="Apollo Pharmacy Private Limited"
                                hint="The registered name, if it differs from the trading name."
                            />

                            <div className="row">
                                <div className="col-md-6">
                                    <TextField
                                        name="gstin"
                                        label="GSTIN"
                                        register={register}
                                        errors={errors}
                                        placeholder="29ABCDE1234F1Z5"
                                    />
                                </div>

                                <div className="col-md-6">
                                    <TextField
                                        name="drug_license_no"
                                        label="Drug Licence No."
                                        register={register}
                                        errors={errors}
                                        placeholder="KA-B-20/2024"
                                    />
                                </div>
                            </div>
                        </Card>
                    </div>

                    {/* Sticky: this column is about half the height of the
                        one beside it, so it would otherwise scroll away and
                        leave a long empty gutter. */}
                    <div className="col-lg-4 form-rail">
                        <Card
                            title="Tenant Configuration"
                            icon="ti ti-server-cog"
                            description={
                                isEdit
                                    ? 'The tenant database already exists under this name.'
                                    : 'Creating this organization provisions its own database.'
                            }
                        >
                            <TextField
                                name="subdomain"
                                label="Subdomain"
                                required
                                register={register}
                                errors={errors}
                                placeholder="apollo"
                                hint={
                                    isEdit
                                        ? 'The tenant database name cannot change.'
                                        : 'Letters, numbers, dashes and underscores only.'
                                }
                            />
                        </Card>

                        <Card
                            title="Branding"
                            icon="ti ti-photo"
                            description="Shown in lists and on the organization's own screens."
                        >
                            <FileField
                                name="profile_image"
                                label="Organization Logo"
                                register={register}
                                errors={errors}
                                accept=".jpg,.jpeg,.png,.webp"
                                previewUrl={organization?.profile_image_url}
                                // Matches the `max:2048` rule on the request —
                                // stated here so an oversized file is caught
                                // before the upload rather than after it.
                                maxSizeMb={2}
                                placeholder="Drop the logo here, or click to browse"
                                progress={progress}
                            />
                        </Card>

                        <Card title="Status" icon="ti ti-toggle-left">
                            <SwitchField
                                name="is_active"
                                label="Active"
                                description="Inactive organizations stay in the list but cannot be used."
                                register={register}
                                errors={errors}
                            />
                        </Card>
                    </div>
                </div>

                {/* Sticky, so a long form does not have to be scrolled to
                    the bottom before it can be saved. */}
                <div className="form-actions">
                    <span className="form-actions-note">
                        {isEdit ? (
                            'Changes take effect immediately.'
                        ) : (
                            <>
                                <i className="ti ti-info-circle me-1" />
                                Creating provisions a tenant database and emails the setup link.
                            </>
                        )}
                    </span>

                    <Button
                        variant="light"
                        onClick={() => navigate('/organizations')}
                        disabled={isSubmitting}
                    >
                        Cancel
                    </Button>

                    <Button type="submit" loading={isSubmitting} icon="ti ti-device-floppy">
                        {isEdit ? 'Update Organization' : 'Create Organization'}
                    </Button>
                </div>
            </form>
        </>
    );
}
