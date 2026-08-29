import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useApiForm } from '@/shared/components/form/useApiForm';
import { FormError } from '@/shared/components/form/Fields';
import { authApi } from '../api';
import { AuthLayout } from '../components/AuthLayout';

interface Values {
    email: string;
}

export default function ForgotPasswordPage() {
    const [sent, setSent] = useState<string | null>(null);

    const {
        register,
        handleSubmit,
        submit,
        formState: { errors, isSubmitting },
    } = useApiForm<Values>({ defaultValues: { email: '' } });

    const onSubmit = handleSubmit(async (values) => {
        const message = await submit(values, ({ email }) => authApi.forgotPassword(email));

        if (message) {
            setSent(message);
        }
    });

    return (
        <AuthLayout>
            <div className="hx-card">
                <div className="hx-head">
                    <h2>Forgot password?</h2>
                    <p>Enter your email and we’ll send you a reset link.</p>
                </div>

                {sent ? (
                    <>
                        <div className="alert alert-success py-2 px-3 fs-13">{sent}</div>
                        <Link to="/login" className="hx-link">
                            ← Back to sign in
                        </Link>
                    </>
                ) : (
                    <form onSubmit={onSubmit} noValidate>
                        <FormError message={errors.root?.message} />

                        <div className="hx-field">
                            <div className={`hx-fl${errors.email ? ' is-invalid' : ''}`}>
                                <input
                                    id="email"
                                    type="email"
                                    placeholder=" "
                                    autoComplete="email"
                                    autoFocus
                                    {...register('email', {
                                        required: 'Email address is required.',
                                    })}
                                />
                                <label htmlFor="email">Email address</label>
                            </div>

                            {errors.email && (
                                <small className="hx-err">{errors.email.message}</small>
                            )}
                        </div>

                        <button type="submit" className="hx-submit" disabled={isSubmitting}>
                            {isSubmitting ? 'Sending…' : 'Send reset link'}
                        </button>

                        <div className="text-center mt-3">
                            <Link to="/login" className="hx-link">
                                ← Back to sign in
                            </Link>
                        </div>
                    </form>
                )}
            </div>
        </AuthLayout>
    );
}
