import { useEffect } from 'react';
import { FormModal } from '@/shared/components/ui/FormModal';
import { SwitchField, TextField } from '@/shared/components/form/Fields';
import { useApiForm } from '@/shared/components/form/useApiForm';
import type { Channel, ChannelAccount } from '../types';

interface Values {
    display_name: string;
    handle: string;
    provider: string;
    business_name: string;
    is_connected: boolean;
    is_verified: boolean;
    webhook_configured: boolean;
}

/**
 * The account a channel sends from.
 *
 * Every switch here is a step of the setup checklist, which is why the
 * checklist is derived rather than stored: this dialog IS the checklist, and
 * two places recording the same fact is how one of them starts lying.
 *
 * Disconnecting clears the connection date on the server rather than here —
 * a card must never be able to show "connected on the 12th" for an account
 * that is not connected.
 */
export function ConnectionModal({
    open,
    channel,
    account,
    onClose,
    onSave,
    saving,
}: {
    open: boolean;
    channel: Channel;
    account: ChannelAccount;
    onClose: () => void;
    onSave: (values: Record<string, unknown>) => Promise<unknown>;
    saving?: boolean;
}) {
    const email = channel === 'email';

    const {
        register,
        handleSubmit,
        reset,
        submit,
        watch,
        formState: { errors, isSubmitting },
    } = useApiForm<Values>({
        defaultValues: {
            display_name: '',
            handle: '',
            provider: '',
            business_name: '',
            is_connected: false,
            is_verified: false,
            webhook_configured: false,
        },
    });

    useEffect(() => {
        if (!open) {
            return;
        }

        reset({
            display_name: account.display_name ?? '',
            handle: account.handle ?? '',
            provider: account.provider ?? (email ? 'Google SMTP' : 'WhatsApp Business API'),
            business_name: String(account.settings?.business_name ?? ''),
            is_connected: account.is_connected,
            is_verified: account.is_verified,
            webhook_configured: account.webhook_configured,
        });
    }, [open, account, email, reset]);

    const connected = watch('is_connected');

    const onSubmit = handleSubmit(async (values) => {
        const result = await submit(values, () =>
            onSave({
                display_name: values.display_name.trim() || null,
                handle: values.handle.trim() || null,
                provider: values.provider.trim() || null,
                is_connected: values.is_connected,

                // Nothing can be verified on an account that is not
                // connected, so the two lower switches follow it down rather
                // than being saved as a state that cannot exist.
                is_verified: values.is_connected && values.is_verified,
                webhook_configured: values.is_connected && values.webhook_configured,

                settings: { business_name: values.business_name.trim() || null },
            }),
        );

        if (result) {
            onClose();
        }
    });

    return (
        <FormModal
            open={open}
            onClose={onClose}
            title="Manage connection"
            subtitle={
                email
                    ? 'The address patients see, and how mail leaves the clinic'
                    : 'The number patients see, and the account behind it'
            }
            icon={<i className={email ? 'ti ti-mail' : 'ti ti-brand-whatsapp'} />}
            size="md"
            onSubmit={onSubmit}
            submitting={isSubmitting || saving}
            submitLabel="Save connection"
        >
            <TextField
                name="display_name"
                label="Display name"
                placeholder="Clinic Care (Official)"
                hint="What the patient sees this arriving from."
                register={register}
                errors={errors}
            />

            <TextField
                name="handle"
                label={email ? 'From address' : 'Phone number'}
                placeholder={email ? 'clinic@careplus.com' : '+91 98765 43210'}
                register={register}
                errors={errors}
            />

            <TextField
                name="provider"
                label="Provider"
                placeholder={email ? 'Google SMTP' : 'WhatsApp Business API'}
                hint="How it is wired up. Shown on the connection card."
                register={register}
                errors={errors}
            />

            {!email && (
                <TextField
                    name="business_name"
                    label="Business name"
                    placeholder="CarePlus Clinic"
                    register={register}
                    errors={errors}
                />
            )}

            {/* These three ARE the setup checklist — it reads them rather than
                keeping its own copy. */}
            <div className="comm-switches">
                <SwitchField
                    name="is_connected"
                    label="Connected"
                    description="The account is live and may send"
                    register={register}
                    errors={errors}
                />

                <SwitchField
                    name="is_verified"
                    label="Verified"
                    description={
                        connected
                            ? 'The provider has verified this number or domain'
                            : 'Connect the account first'
                    }
                    register={register}
                    errors={errors}
                />

                <SwitchField
                    name="webhook_configured"
                    label="Webhook configured"
                    description={
                        connected
                            ? 'Delivery receipts are reaching this clinic'
                            : 'Connect the account first'
                    }
                    register={register}
                    errors={errors}
                />
            </div>

            {!connected && (
                <p className="pf-soon">
                    <i className="ti ti-info-circle" aria-hidden="true" />
                    Switching this off stops every automation on this channel, and clears the
                    connection date. Nothing already sent changes.
                </p>
            )}
        </FormModal>
    );
}
