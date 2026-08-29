import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { http } from '@/shared/api/http';
import { useApiForm } from '@/shared/components/form/useApiForm';
import { FormError } from '@/shared/components/form/Fields';
import { LoadingBlock } from '@/shared/components/ui/Feedback';
import { AuthLayout } from '@/core/auth/components/AuthLayout';
import type { ApiResponse } from '@/shared/types/api';

interface SetupInfo {
    organization_name: string;
    email: string;
    contact_person_name: string | null;
}

interface SetupResult {
    organization_name: string;
    email: string;
    login_url: string;
}

interface Values {
    admin_name: string;
    password: string;
    password_confirmation: string;
}

/**
 * Public page reached from the invitation email. Creates the first admin
 * user inside the organization's own tenant database.
 */
export default function OrganizationSetupPage() {
    const { token = '' } = useParams();
    const [done, setDone] = useState<SetupResult | null>(null);

    const { data, isLoading, isError, error } = useQuery({
        queryKey: ['organization-setup', token],
        queryFn: async () => {
            const response = await http.get<ApiResponse<SetupInfo>>(`/organization-setup/${token}`);

            return response.data.data;
        },
        retry: false,
    });

    const {
        register,
        handleSubmit,
        submit,
        formState: { errors, isSubmitting },
    } = useApiForm<Values>({
        defaultValues: { admin_name: '', password: '', password_confirmation: '' },
    });

    const onSubmit = handleSubmit(async (values) => {
        const result = await submit(values, async (v) => {
            const response = await http.post<ApiResponse<SetupResult>>(
                `/organization-setup/${token}`,
                v,
            );

            return response.data.data;
        });

        if (result) {
            setDone(result);
        }
    });

    if (isLoading) {
        return (
            <AuthLayout>
                <div className="hx-card">
                    <LoadingBlock label="Checking your setup link…" />
                </div>
            </AuthLayout>
        );
    }

    if (isError) {
        const message =
            (error as { response?: { data?: { message?: string } } })?.response?.data?.message ??
            'This setup link is not valid.';

        return (
            <AuthLayout>
                <div className="hx-card">
                    <div className="hx-head">
                        <h2>Link unavailable</h2>
                        <p>{message}</p>
                    </div>
                    <p className="text-muted fs-13 mb-0">
                        Ask your administrator to send a new invitation.
                    </p>
                </div>
            </AuthLayout>
        );
    }

    if (done) {
        return (
            <AuthLayout>
                <div className="hx-card">
                    <div className="hx-head">
                        <h2>You’re all set</h2>
                        <p>
                            {done.organization_name} is ready. Sign in with{' '}
                            <strong>{done.email}</strong>.
                        </p>
                    </div>

                    <a
                        href={done.login_url}
                        className="hx-submit d-block text-center text-decoration-none"
                    >
                        Go to your workspace
                    </a>
                </div>
            </AuthLayout>
        );
    }

    return (
        <AuthLayout>
            <div className="hx-card">
                <div className="hx-head">
                    <h2>Set up {data?.organization_name}</h2>
                    <p>Create the administrator account for your organization.</p>
                </div>

                <form onSubmit={onSubmit} noValidate>
                    <FormError message={errors.root?.message} />

                    <div className="hx-field">
                        <div className={`hx-fl${errors.admin_name ? ' is-invalid' : ''}`}>
                            <input
                                id="admin_name"
                                type="text"
                                placeholder=" "
                                autoFocus
                                {...register('admin_name', { required: 'Your name is required.' })}
                            />
                            <label htmlFor="admin_name">Administrator name</label>
                        </div>

                        {errors.admin_name && (
                            <small className="hx-err">{errors.admin_name.message}</small>
                        )}
                    </div>

                    <div className="hx-field">
                        <div className={`hx-fl${errors.password ? ' is-invalid' : ''}`}>
                            <input
                                id="password"
                                type="password"
                                placeholder=" "
                                autoComplete="new-password"
                                {...register('password', { required: 'Password is required.' })}
                            />
                            <label htmlFor="password">Password</label>
                        </div>

                        {errors.password ? (
                            <small className="hx-err">{errors.password.message}</small>
                        ) : (
                            <small className="text-muted d-block mt-1 fs-12">
                                At least 8 characters, with upper and lower case, a number and a
                                symbol.
                            </small>
                        )}
                    </div>

                    <div className="hx-field">
                        <div
                            className={`hx-fl${errors.password_confirmation ? ' is-invalid' : ''}`}
                        >
                            <input
                                id="password_confirmation"
                                type="password"
                                placeholder=" "
                                autoComplete="new-password"
                                {...register('password_confirmation', {
                                    required: 'Please confirm your password.',
                                })}
                            />
                            <label htmlFor="password_confirmation">Confirm password</label>
                        </div>

                        {errors.password_confirmation && (
                            <small className="hx-err">{errors.password_confirmation.message}</small>
                        )}
                    </div>

                    <button type="submit" className="hx-submit" disabled={isSubmitting}>
                        {isSubmitting ? 'Setting up…' : 'Complete setup'}
                    </button>
                </form>
            </div>
        </AuthLayout>
    );
}
