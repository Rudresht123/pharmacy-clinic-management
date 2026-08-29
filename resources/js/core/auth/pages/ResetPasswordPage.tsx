import { useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { notify } from '@/shared/utils/notify';
import { useApiForm } from '@/shared/components/form/useApiForm';
import { FormError } from '@/shared/components/form/Fields';
import { authApi } from '../api';
import { AuthLayout } from '../components/AuthLayout';

interface Values {
    password: string;
    password_confirmation: string;
}

export default function ResetPasswordPage() {
    const { token = '' } = useParams();
    const [searchParams] = useSearchParams();
    const email = searchParams.get('email') ?? '';
    const navigate = useNavigate();

    const {
        register,
        handleSubmit,
        submit,
        formState: { errors, isSubmitting },
    } = useApiForm<Values>({ defaultValues: { password: '', password_confirmation: '' } });

    const onSubmit = handleSubmit(async (values) => {
        const done = await submit(values, async (v) => {
            await authApi.resetPassword({ token, email, ...v });

            return true;
        });

        if (done) {
            notify.success('Password reset', {
                description: 'You can now sign in with your new password.',
            });
            navigate('/login', { replace: true });
        }
    });

    return (
        <AuthLayout>
            <div className="hx-card">
                <div className="hx-head">
                    <h2>Set a new password</h2>
                    <p>
                        {email ? `Resetting the password for ${email}.` : 'Choose a new password.'}
                    </p>
                </div>

                <form onSubmit={onSubmit} noValidate>
                    <FormError message={errors.root?.message} />

                    <div className="hx-field">
                        <div className={`hx-fl${errors.password ? ' is-invalid' : ''}`}>
                            <input
                                id="password"
                                type="password"
                                placeholder=" "
                                autoComplete="new-password"
                                autoFocus
                                {...register('password', { required: 'Password is required.' })}
                            />
                            <label htmlFor="password">New password</label>
                        </div>

                        {errors.password && (
                            <small className="hx-err">{errors.password.message}</small>
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
                        {isSubmitting ? 'Saving…' : 'Reset password'}
                    </button>
                </form>
            </div>
        </AuthLayout>
    );
}
