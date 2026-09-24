import { useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import { Tabs, type TabItem } from '@/shared/components/ui/Tabs';
import { SelectField, TextField } from '@/shared/components/form/Fields';
import { useApiForm } from '@/shared/components/form/useApiForm';
import { useMessagingSettings, useSaveMessagingSettings, useTestMessaging } from '../api';
import type { MessagingChannel } from '../types';

interface Values {
    provider_key: string;
    credentials: Record<string, string>;
}

const CHANNELS: readonly TabItem<MessagingChannel>[] = [
    { value: 'whatsapp', label: 'WhatsApp', icon: 'ti ti-brand-whatsapp' },
    { value: 'email', label: 'Email', icon: 'ti ti-mail' },
];

const BLURB: Record<MessagingChannel, string> = {
    whatsapp:
        'One WhatsApp Business account for the whole platform. Every clinic sends through it, so patients see the same number whichever clinic messaged them.',
    email:
        'One mail relay for the whole platform. A clinic sets the address patients see on its own connection screen; this is the server behind it.',
};

/**
 * The accounts every organization's messages go out through.
 *
 * PLATFORM-WIDE, and that is the whole point of it living here: there is one
 * commercial relationship with each provider, so a clinic setting these would
 * be changing what every other clinic sends through.
 *
 * NO SECRET IS EVER RENDERED. A saved token or password shows as a
 * placeholder, and leaving the box empty keeps it — without that rule, opening
 * this to correct a host name and saving would wipe the password and stop
 * messaging across the entire platform at once.
 *
 * One screen for both channels because the job is identical. The form builds
 * itself from the server's field list, so adding a provider is a config change
 * and not a second edit here that somebody forgets to make.
 */
export default function MessagingSettingsPage() {
    const [searchParams, setSearchParams] = useSearchParams();

    const channel = (searchParams.get('channel') as MessagingChannel | null) ?? 'whatsapp';

    const { data: settings, isLoading } = useMessagingSettings(channel);
    const save = useSaveMessagingSettings(channel);
    const test = useTestMessaging(channel);

    /* The outcome lives on the page rather than in a toast: "did it work" is
       the question this screen exists to answer. */
    const [result, setResult] = useState<{ ok: boolean; message: string } | null>(null);

    const {
        register,
        control,
        handleSubmit,
        reset,
        submit,
        watch,
        formState: { errors, isSubmitting },
    } = useApiForm<Values>({ defaultValues: { provider_key: '', credentials: {} } });

    const selected = watch('provider_key');

    const option = useMemo(
        () => settings?.options.find((entry) => entry.key === selected),
        [settings, selected],
    );

    useEffect(() => {
        setResult(null);

        if (!settings) {
            return;
        }

        const current = settings.options.find((entry) => entry.key === settings.current);

        reset({
            provider_key: settings.current,
            credentials: Object.fromEntries(
                (current?.fields ?? []).map((field) => [field.name, field.value ?? '']),
            ),
        });
    }, [settings, reset]);

    const onSubmit = handleSubmit(async (values) => {
        setResult(null);

        await submit(values, () =>
            save.mutateAsync({
                provider_key: values.provider_key,
                credentials: values.credentials ?? {},
            }),
        );
    });

    const onTest = async () => {
        setResult(null);

        try {
            const response = await test.mutateAsync();

            setResult({
                ok: Boolean(response.data?.success),
                message: response.message ?? 'No answer from the provider.',
            });
        } catch {
            setResult({ ok: false, message: 'Could not reach the server.' });
        }
    };

    /* Only one provider means the choice is not a choice, and a dropdown with
       a single option is a control that teaches people nothing. */
    const choices = settings?.options ?? [];

    return (
        <>
            <PageHeader
                title="Messaging"
                subtitle="The accounts every organization's messages are sent through"
                icon="ti ti-send"
            />

            <Tabs
                tabs={CHANNELS}
                value={channel}
                onChange={(next) => setSearchParams({ channel: next }, { replace: true })}
                label="Messaging channels"
            />

            <div className="ms-grid">
                <Card className="ms-card">
                    <form onSubmit={onSubmit} noValidate>
                        {choices.length > 1 && (
                            <SelectField
                                name="provider_key"
                                label="Provider"
                                required
                                control={control}
                                errors={errors}
                                options={choices.map((entry) => ({
                                    value: entry.key,
                                    label: entry.label,
                                }))}
                                hint="Changing this does not resend anything already queued."
                            />
                        )}

                        <div className="ms-fields">
                            {option?.fields.map((field) =>
                                field.options ? (
                                    <SelectField
                                        key={field.name}
                                        name={`credentials.${field.name}` as never}
                                        label={field.label}
                                        required={field.required}
                                        control={control}
                                        errors={errors}
                                        options={field.options}
                                        hint={field.hint ?? undefined}
                                    />
                                ) : (
                                    <TextField
                                        key={field.name}
                                        name={`credentials.${field.name}` as never}
                                        label={field.label}
                                        required={field.required && !field.is_set}
                                        type={field.secret ? 'password' : 'text'}
                                        autoComplete="off"
                                        placeholder={
                                            field.secret && field.is_set
                                                ? 'Saved — leave blank to keep it'
                                                : (field.placeholder ?? undefined)
                                        }
                                        hint={
                                            field.from_env
                                                ? 'Currently coming from the deployment config. Anything entered here replaces it.'
                                                : field.secret
                                                  ? 'Stored encrypted. It is never sent back to this screen.'
                                                  : (field.hint ?? undefined)
                                        }
                                        register={register}
                                        errors={errors}
                                    />
                                ),
                            )}
                        </div>

                        {option && option.fields.length === 0 && (
                            <p className="pf-soon">
                                <i className="ti ti-info-circle" aria-hidden="true" />
                                This provider needs no credentials. Messages are written to the
                                server log instead of being sent, which is what development should
                                do — and it refuses to run in production.
                            </p>
                        )}

                        {/* Testing is separate from saving on purpose: it checks
                            the credentials ALREADY STORED, so anything typed
                            above has to be saved before it can be proved. */}
                        <div className="ms-actions">
                            <Button
                                type="submit"
                                icon="ti ti-check"
                                disabled={isSubmitting || isLoading}
                            >
                                Save
                            </Button>

                            <Button
                                type="button"
                                variant="light"
                                icon={test.isPending ? 'ti ti-loader-2 comm-spin' : 'ti ti-plug'}
                                onClick={onTest}
                                disabled={test.isPending}
                            >
                                {test.isPending ? 'Checking…' : 'Test connection'}
                            </Button>

                            <span className="ms-note">
                                Checks the saved credentials. Nobody is messaged.
                            </span>
                        </div>

                        {result && (
                            <p className={`comm-provider-result ${result.ok ? 'is-ok' : 'is-bad'}`}>
                                <i
                                    className={
                                        result.ok ? 'ti ti-circle-check' : 'ti ti-alert-circle'
                                    }
                                    aria-hidden="true"
                                />
                                {result.message}
                            </p>
                        )}

                        {!result && settings?.verification_error && (
                            <p className="comm-provider-result is-bad">
                                <i className="ti ti-alert-circle" aria-hidden="true" />
                                Last check failed: {settings.verification_error}
                            </p>
                        )}
                    </form>
                </Card>

                <Card className="ms-card ms-aside">
                    <h6 className="ms-aside-head">
                        <i className={CHANNELS.find((c) => c.value === channel)?.icon} />
                        What this changes
                    </h6>

                    <p>{BLURB[channel]}</p>

                    <p>
                        Messages are queued rather than sent inline, so a worker has to be running
                        on the <code>{channel === 'email' ? 'email' : 'whatsapp'}</code> queue for
                        anything to leave.
                    </p>

                    {settings?.verified_at && (
                        <p className="ms-verified">
                            <i className="ti ti-circle-check" aria-hidden="true" />
                            Last checked {new Date(settings.verified_at).toLocaleString()}
                        </p>
                    )}
                </Card>
            </div>
        </>
    );
}
