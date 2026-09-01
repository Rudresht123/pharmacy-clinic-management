import { useLocation, useNavigate } from 'react-router-dom';
import { useEffect, useState } from 'react';
import { useApiForm } from '@/shared/components/form/useApiForm';
import { FormError } from '@/shared/components/form/Fields';
import { AuthLayout } from '@/core/auth/components/AuthLayout';
import { initials } from '@/shared/utils/format';
import { useTenantAuth } from '../TenantAuthProvider';
import { tenantBrandingApi, type TenantOrganization } from '../api';

interface TenantLoginValues {
    email: string;
    password: string;
    remember: boolean;
}

/**
 * An organization's own staff sign in here — no subdomain field, unlike the
 * MVP this replaces: the request's Host header (clinic.hms.local) already
 * carries which tenant this is (see LoginRequest::resolveSubdomain()).
 */
export default function TenantLoginPage() {
    const { login } = useTenantAuth();
    const navigate = useNavigate();
    const location = useLocation();
    const [showPassword, setShowPassword] = useState(false);

    // Purely cosmetic — a failed/slow lookup just leaves the platform's own
    // mark and generic copy in place, never blocks the form.
    const [branding, setBranding] = useState<TenantOrganization | null>(null);

    useEffect(() => {
        let cancelled = false;

        tenantBrandingApi
            .get()
            .then((org) => {
                if (!cancelled) {
                    setBranding(org);
                }
            })
            .catch(() => undefined);

        return () => {
            cancelled = true;
        };
    }, []);

    const {
        register,
        handleSubmit,
        submit,
        formState: { errors, isSubmitting },
    } = useApiForm<TenantLoginValues>({
        defaultValues: { email: '', password: '', remember: false },
    });

    const onSubmit = handleSubmit(async (values) => {
        const user = await submit(values, login);

        if (user) {
            const from = (location.state as { from?: string } | null)?.from;
            navigate(from ?? '/dashboard', { replace: true });
        }
    });

    return (
        <AuthLayout
            // Left undefined (not null) while branding hasn't loaded yet, so
            // AuthLayout's own default logo shows instead of nothing.
            brand={
                branding
                    ? (
                        <div className="hx-brand-org">
                            {branding.has_logo ? (
                                <img
                                    src={branding.logo_url}
                                    alt=""
                                    className="hx-brand-org-logo"
                                />
                            ) : (
                                <span
                                    className="hx-brand-org-logo hx-brand-org-logo--initials"
                                    aria-hidden="true"
                                >
                                    {initials(branding.name)}
                                </span>
                            )}

                            <span className="hx-brand-org-name">{branding.name}</span>
                        </div>
                    )
                    : undefined
            }
            eyebrow="Organization Workspace"
            heading={
                <>
                    Your clinic, <em>running smoothly</em>.
                </>
            }
            description="Patients, staff, billing and stock — everything your organization runs on, in one secure workspace."
            appUrl={branding ? `${branding.subdomain}.hms.local` : 'yourclinic.hms.local'}
            kpi={{ value: '248', delta: '8.6%', caption: 'Patients seen this week' }}
            features={[
                {
                    icon: 'ti ti-users-group',
                    title: 'Role-based access',
                    text: 'Owners and staff each see their own scope.',
                },
                {
                    icon: 'ti ti-database',
                    title: 'Isolated by design',
                    text: "Your organization's data lives in its own database.",
                },
                {
                    icon: 'ti ti-shield-lock',
                    title: 'Audit-ready records',
                    text: 'Changes land in an append-only log.',
                },
            ]}
            footerBrand="Workspace"
        >
            <div className="hx-card">
                <div className="hx-head">
                    <h2>Welcome back</h2>
                    <p>
                        {branding
                            ? `Sign in to ${branding.name}'s workspace.`
                            : "Sign in to your organization's workspace."}
                    </p>
                </div>

                <FormError message={errors.root?.message} />

                <form onSubmit={onSubmit} noValidate>
                    <div className="hx-field">
                        <div className={`hx-fl has-icon${errors.email ? ' is-invalid' : ''}`}>
                            <i className="ti ti-mail hx-fl-icon" />
                            <input
                                id="email"
                                type="email"
                                placeholder=" "
                                autoComplete="email"
                                autoFocus
                                {...register('email', { required: 'Email address is required.' })}
                            />
                            <label htmlFor="email">Email address</label>
                        </div>

                        {errors.email && <small className="hx-err">{errors.email.message}</small>}
                    </div>

                    <div className="hx-field">
                        <div className={`hx-fl has-icon${errors.password ? ' is-invalid' : ''}`}>
                            <i className="ti ti-lock hx-fl-icon" />
                            <input
                                id="password"
                                type={showPassword ? 'text' : 'password'}
                                placeholder=" "
                                autoComplete="current-password"
                                {...register('password', { required: 'Password is required.' })}
                            />
                            <label htmlFor="password">Password</label>

                            <button
                                type="button"
                                className="hx-eye"
                                onClick={() => setShowPassword((visible) => !visible)}
                                aria-label={showPassword ? 'Hide password' : 'Show password'}
                            >
                                <i className={showPassword ? 'ti ti-eye-off' : 'ti ti-eye'} />
                            </button>
                        </div>

                        {errors.password && (
                            <small className="hx-err">{errors.password.message}</small>
                        )}
                    </div>

                    <div className="hx-row">
                        <label className="hx-check">
                            <input type="checkbox" {...register('remember')} />
                            Remember me
                        </label>
                    </div>

                    <button type="submit" className="hx-submit" disabled={isSubmitting}>
                        {isSubmitting && (
                            <span
                                className="spinner-border spinner-border-sm me-2"
                                role="status"
                                aria-hidden="true"
                            />
                        )}
                        {isSubmitting ? 'Signing in…' : 'Sign in'}
                    </button>
                </form>
            </div>

            <p className="hx-foot">Trouble signing in? Contact your organization's admin.</p>
        </AuthLayout>
    );
}
