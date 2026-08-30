import { Link, useLocation, useNavigate } from 'react-router-dom';
import { useState } from 'react';
import { useApiForm } from '@/shared/components/form/useApiForm';
import { FormError } from '@/shared/components/form/Fields';
import { useAuth } from '@/core/auth/AuthProvider';
import { useLock } from '@/core/auth/LockProvider';
import { AuthLayout } from '../components/AuthLayout';

interface LoginValues {
    email: string;
    password: string;
    remember: boolean;
}

export default function LoginPage() {
    const { login } = useAuth();
    const { unlock } = useLock();
    const navigate = useNavigate();
    const location = useLocation();
    const [showPassword, setShowPassword] = useState(false);

    const {
        register,
        handleSubmit,
        submit,
        formState: { errors, isSubmitting },
    } = useApiForm<LoginValues>({
        defaultValues: { email: '', password: '', remember: false },
    });

    const onSubmit = handleSubmit(async (values) => {
        const user = await submit(values, login);

        if (user) {
            // A stale lock from a previous session — the browser was closed
            // while locked, or a different user signed in after that —
            // must not survive a fresh, successful login.
            unlock();

            const from = (location.state as { from?: string } | null)?.from;
            navigate(from ?? '/dashboard', { replace: true });
        }
    });

    return (
        <AuthLayout>
            <div className="hx-card">
                <div className="hx-head">
                    <h2>Welcome back</h2>
                    <p>Sign in to continue to your dashboard.</p>
                </div>

                <FormError message={errors.root?.message} />

                <form onSubmit={onSubmit} noValidate>
                    <div className="hx-field">
                        <div className={`hx-fl${errors.email ? ' is-invalid' : ''}`}>
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
                        <div className={`hx-fl${errors.password ? ' is-invalid' : ''}`}>
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

                        <Link to="/forgot-password" className="hx-link">
                            Forgot password?
                        </Link>
                    </div>

                    <button type="submit" className="hx-submit" disabled={isSubmitting}>
                        {isSubmitting ? 'Signing in…' : 'Sign in'}
                    </button>
                </form>
            </div>

            <p className="hx-foot">Trouble signing in? Contact your administrator.</p>
        </AuthLayout>
    );
}
