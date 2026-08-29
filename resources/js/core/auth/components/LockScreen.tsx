import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../AuthProvider';
import { http, getValidationErrors, resolveErrorMessage } from '@/shared/api/http';
import { initials } from '@/shared/utils/format';
import { notify } from '@/shared/utils/notify';

/**
 * Full-screen lock. The password is checked against
 * POST /auth/confirm-password, so unlocking proves identity without
 * tearing down and rebuilding the session.
 */
export function LockScreen({ onUnlock }: { onUnlock: () => void }) {
    const { user, logout } = useAuth();
    const navigate = useNavigate();

    const [password, setPassword] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);
    const inputRef = useRef<HTMLInputElement>(null);

    // Hold the page still behind the lock and take focus.
    useEffect(() => {
        const previous = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        inputRef.current?.focus();

        return () => {
            document.body.style.overflow = previous;
        };
    }, []);

    async function handleSubmit(event: React.FormEvent) {
        event.preventDefault();

        if (!password || submitting) {
            return;
        }

        setSubmitting(true);
        setError(null);

        try {
            await http.post('/admin/auth/confirm-password', { password });

            setPassword('');
            onUnlock();
        } catch (requestError) {
            const validation = getValidationErrors(requestError);

            setError(validation?.password?.[0] ?? resolveErrorMessage(requestError));
            setPassword('');
            inputRef.current?.focus();
        } finally {
            setSubmitting(false);
        }
    }

    async function handleSignOut() {
        await logout();
        onUnlock();
        navigate('/login', { replace: true });
        notify.info('Signed out');
    }

    return createPortal(
        <div className="lock" role="dialog" aria-modal="true" aria-label="Screen locked">
            <div className="lock-card">
                <span className="lock-avatar">{user ? initials(user.name) : '?'}</span>

                <span className="lock-badge">
                    <i className="ti ti-lock" />
                    Screen locked
                </span>

                <h2>{user?.name}</h2>
                <p className="lock-email">{user?.email}</p>

                <form onSubmit={handleSubmit} noValidate>
                    <div className={`lock-field${error ? ' is-invalid' : ''}`}>
                        <i className="ti ti-key" />

                        <input
                            ref={inputRef}
                            type="password"
                            value={password}
                            onChange={(event) => {
                                setPassword(event.target.value);
                                setError(null);
                            }}
                            placeholder="Enter your password"
                            autoComplete="current-password"
                            disabled={submitting}
                        />
                    </div>

                    {error && <p className="lock-error">{error}</p>}

                    <button
                        type="submit"
                        className="lock-submit"
                        disabled={submitting || !password}
                    >
                        {submitting ? 'Unlocking…' : 'Unlock'}
                    </button>
                </form>

                <button type="button" className="lock-signout" onClick={handleSignOut}>
                    Not you? Sign out
                </button>
            </div>
        </div>,
        document.body,
    );
}
