import { useEffect } from 'react';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import { TextField } from '@/shared/components/form/Fields';
import { useApiForm } from '@/shared/components/form/useApiForm';
import { useMobileServer, useSaveMobileServer } from '../api';

interface Values {
    address: string;
}

/**
 * Where the mobile app finds this server while it is being developed.
 *
 * The laptop serving the API is given a new Wi-Fi address by the router
 * whenever it likes, and every phone then says "cannot reach the server".
 * Typing the new address here — instead of into the app's code — publishes it
 * to Firebase, which the app reads on opening wherever it is.
 *
 * The suggestions are this computer's own addresses, so the usual change is
 * one click and Save.
 */
export default function MobileAppSettingsPage() {
    const { data: server, isLoading } = useMobileServer();
    const save = useSaveMobileServer();

    const {
        register,
        handleSubmit,
        reset,
        setValue,
        submit,
        formState: { errors, isSubmitting },
    } = useApiForm<Values>({ defaultValues: { address: '' } });

    useEffect(() => {
        if (server) {
            reset({ address: server.address ?? '' });
        }
    }, [server, reset]);

    const onSubmit = handleSubmit(async (values) => {
        await submit(values, () => save.mutateAsync({ address: values.address ?? '' }));
    });

    return (
        <>
            <PageHeader
                title="Mobile App"
                subtitle="Where phones running a development build find this server"
                icon="ti ti-device-mobile"
            />

            <div className="ms-grid">
                <Card className="ms-card">
                    <form onSubmit={onSubmit} noValidate>
                        <TextField
                            name="address"
                            label="Server address"
                            placeholder="192.168.1.24:8000"
                            autoComplete="off"
                            hint="This computer's Wi-Fi address and the port the API runs on. Leave it empty to let phones search on their own."
                            register={register}
                            errors={errors}
                        />

                        {(server?.suggestions.length ?? 0) > 0 && (
                            <div className="mb-3">
                                <div className="ms-note mb-2">This computer's addresses</div>
                                <div className="d-flex flex-wrap gap-2">
                                    {server?.suggestions.map((suggestion) => (
                                        <Button
                                            key={suggestion}
                                            type="button"
                                            variant="light"
                                            size="sm"
                                            icon="ti ti-arrow-down-left"
                                            onClick={() =>
                                                setValue('address', suggestion, { shouldDirty: true })
                                            }
                                        >
                                            {suggestion.replace(/^http:\/\//, '')}
                                        </Button>
                                    ))}
                                </div>
                            </div>
                        )}

                        <div className="ms-actions">
                            <Button
                                type="submit"
                                icon="ti ti-check"
                                disabled={isSubmitting || isLoading}
                            >
                                {isSubmitting ? 'Saving…' : 'Save'}
                            </Button>

                            <span className="ms-note">
                                Phones pick it up the next time the app opens.
                            </span>
                        </div>

                        {server?.publish_error ? (
                            <p className="comm-provider-result is-bad">
                                <i className="ti ti-alert-circle" aria-hidden="true" />
                                Saved here, but it did not reach the phones: {server.publish_error}
                            </p>
                        ) : (
                            server?.api_base && (
                                <p className="comm-provider-result is-ok">
                                    <i className="ti ti-circle-check" aria-hidden="true" />
                                    Phones use <code>{server.api_base}</code>
                                    {server.published_at &&
                                        ` — sent ${new Date(server.published_at).toLocaleString()}`}
                                </p>
                            )
                        )}
                    </form>
                </Card>

                <Card className="ms-card ms-aside">
                    <h6 className="ms-aside-head">
                        <i className="ti ti-info-circle" />
                        How it works
                    </h6>

                    <p>
                        Saving sends the address to Firebase. The app reads it from there when it
                        opens — so it works even after this computer's address has changed, as
                        long as the phone has internet.
                    </p>

                    <p>
                        The phone must be on the <strong>same Wi-Fi</strong> as this computer, and
                        the API must be started for the network:{' '}
                        <code>php artisan serve --host=0.0.0.0 --port=8000</code>. Live updates and
                        calls also need <code>php artisan reverb:start</code>.
                    </p>

                    <p>
                        Development builds only. An app built for release with its own domain
                        ignores this, so nothing here can redirect a real installation.
                    </p>
                </Card>
            </div>
        </>
    );
}
