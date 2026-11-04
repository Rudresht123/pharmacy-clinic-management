import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation } from '@tanstack/react-query';
import {
    get,
    type FieldErrors,
    type FieldValues,
    type Path,
    type UseFormRegister,
} from 'react-hook-form';
import { getValidationErrors } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import { Avatar } from '@/shared/components/ui/Avatar';
import { Button } from '@/shared/components/ui/Button';
import { FormError } from '@/shared/components/form/Fields';
import { useApiForm } from '@/shared/components/form/useApiForm';
import { cn } from '@/shared/utils/cn';
import { HospitalArt, ShieldArt } from './ProfileArt';
import { ProfileSummaryCard, type ProfileSummaryRow } from './ProfileSummaryCard';

interface ProfileValues {
    name: string;
    email: string;
}

interface PasswordValues {
    current_password: string;
    password: string;
    password_confirmation: string;
}

/** The fields both kinds of account (platform and tenant) have in common. */
interface ProfilePerson {
    name: string;
    email: string;
    avatar_url: string | null;
    is_active: boolean;
    last_login_at: string | null;
}

interface ProfileScreenProps<TUser extends ProfilePerson> {
    user: TUser | null;
    roleLabel: string;
    /** The summary card's lines between the email and the last sign-in. */
    rows: ProfileSummaryRow[];
    onSaved(user: TUser): void;
    api: {
        updateProfile(values: ProfileValues): Promise<TUser>;
        updatePassword(values: PasswordValues): Promise<unknown>;
        uploadAvatar(file: File): Promise<TUser>;
        deleteAvatar(): Promise<TUser>;
    };
}

/**
 * Mirrors the rule the server enforces on a new password
 * (Password::min(8)->mixedCase()->numbers()->symbols()), so the list says what
 * will actually be accepted. Change them together.
 */
const PASSWORD_RULES: { label: string; met(value: string): boolean }[] = [
    { label: 'At least 8 characters long', met: (value) => [...value].length >= 8 },
    { label: 'Include an uppercase letter (A–Z)', met: (value) => /\p{Lu}/u.test(value) },
    { label: 'Include a lowercase letter (a–z)', met: (value) => /\p{Ll}/u.test(value) },
    { label: 'Include a number (0–9)', met: (value) => /\p{N}/u.test(value) },
    { label: 'Include a special character (e.g., !@#$%)', met: (value) => /[\p{Z}\p{S}\p{P}]/u.test(value) },
];

type Section = 'personal' | 'security';

/** The My Profile page, shared by the platform admin and the clinic staff. */
export function ProfileScreen<TUser extends ProfilePerson>({
    user,
    roleLabel,
    rows,
    onSaved,
    api,
}: ProfileScreenProps<TUser>) {
    const avatarInput = useRef<HTMLInputElement>(null);
    const [section, setSection] = useState<Section>('personal');

    const profileForm = useApiForm<ProfileValues>({
        defaultValues: { name: '', email: '' },
    });

    const passwordForm = useApiForm<PasswordValues>({
        defaultValues: { current_password: '', password: '', password_confirmation: '' },
    });

    const newPassword = passwordForm.watch('password') ?? '';

    // Only when these two change — a new photo also updates `user`, and must
    // not wipe a name someone is halfway through typing.
    useEffect(() => {
        if (user) {
            profileForm.reset({ name: user.name, email: user.email });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [user?.name, user?.email]);

    const updateProfile = useMutation({
        mutationFn: api.updateProfile,
        onSuccess: (updated) => {
            onSaved(updated);
            notify.success('Profile updated');
        },
    });

    const updatePassword = useMutation({
        mutationFn: api.updatePassword,
        onSuccess: () => {
            passwordForm.reset({ current_password: '', password: '', password_confirmation: '' });
            notify.success('Password updated', {
                description: 'Use it the next time you sign in.',
            });
        },
    });

    const uploadAvatar = useMutation({
        mutationFn: api.uploadAvatar,
        onSuccess: (updated) => {
            onSaved(updated);
            notify.success('Photo updated');
        },
        // A 422 raises no toast of its own (forms show those inline), and this
        // picker has no form: too big or the wrong type would otherwise say nothing.
        onError: (error) => {
            const message = Object.values(getValidationErrors(error) ?? {})[0]?.[0];

            if (message) {
                notify.error(message);
            }
        },
    });

    const deleteAvatar = useMutation({
        mutationFn: api.deleteAvatar,
        onSuccess: (updated) => {
            onSaved(updated);
            notify.success('Photo removed');
        },
    });

    function onAvatarChosen(event: React.ChangeEvent<HTMLInputElement>) {
        const file = event.target.files?.[0];
        event.target.value = '';

        if (file) {
            uploadAvatar.mutate(file);
        }
    }

    const pickPhoto = () => avatarInput.current?.click();

    const onProfileSubmit = profileForm.handleSubmit(async (values) => {
        await profileForm.submit(values, updateProfile.mutateAsync);
    });

    const onPasswordSubmit = passwordForm.handleSubmit(async (values) => {
        await passwordForm.submit(values, updatePassword.mutateAsync);
    });

    /**
     * Both sections stay on the page together; a tab takes you to its section
     * and puts the cursor in its first field, and working in a section marks
     * its tab — so the underline always says where you are.
     */
    function goTo(next: Section) {
        setSection(next);

        if (next === 'personal') {
            profileForm.setFocus('name');
        } else {
            passwordForm.setFocus('current_password');
        }
    }

    return (
        <div className="ppf-page">
            <header className="ppf-hero">
                <div className="ppf-hero-title">
                    <span className="ppf-hero-icon" aria-hidden="true">
                        <i className="ti ti-user" />
                    </span>

                    <div>
                        <h1>My Profile</h1>
                        <p>Manage your personal information, photo and password.</p>
                    </div>
                </div>

                <nav className="ppf-crumbs" aria-label="Breadcrumb">
                    <Link to="/dashboard" title="Dashboard" aria-label="Dashboard">
                        <i className="ti ti-home" />
                    </Link>
                    <i className="ti ti-chevron-right ppf-crumb-sep" aria-hidden="true" />
                    <span>Account</span>
                    <i className="ti ti-chevron-right ppf-crumb-sep" aria-hidden="true" />
                    <span className="ppf-crumb-now">My Profile</span>
                </nav>

                <HospitalArt />
            </header>

            <input
                ref={avatarInput}
                type="file"
                accept="image/png,image/jpeg,image/webp"
                className="d-none"
                onChange={onAvatarChosen}
            />

            <div className="ppf-layout">
                {user && (
                    <ProfileSummaryCard
                        name={user.name}
                        email={user.email}
                        avatarUrl={user.avatar_url}
                        roleLabel={roleLabel}
                        isActive={user.is_active}
                        lastLoginAt={user.last_login_at}
                        rows={rows}
                        onChangePhoto={pickPhoto}
                        photoBusy={uploadAvatar.isPending}
                    />
                )}

                <div className="card ppf-panel">
                    <nav className="ppf-tabs" aria-label="Profile sections">
                        <button
                            type="button"
                            className={cn('ppf-tab', section === 'personal' && 'is-active')}
                            aria-current={section === 'personal' ? 'true' : undefined}
                            onClick={() => goTo('personal')}
                        >
                            <i className="ti ti-user" />
                            Personal Information
                        </button>

                        <button
                            type="button"
                            className={cn('ppf-tab', section === 'security' && 'is-active')}
                            aria-current={section === 'security' ? 'true' : undefined}
                            onClick={() => goTo('security')}
                        >
                            <i className="ti ti-lock" />
                            Security &amp; Password
                        </button>
                    </nav>

                    <div className="ppf-sections">
                        <section className="ppf-section" onFocusCapture={() => setSection('personal')}>
                            <SectionHead
                                icon="ti ti-id"
                                title="Personal Information"
                                description="Update your photo, name and email."
                            />

                            <div className="ppf-personal">
                                <div className="ppf-photo-box">
                                    <div className="ppf-photo-frame">
                                        <Avatar
                                            name={user?.name}
                                            src={user?.avatar_url}
                                            size={104}
                                            caption={roleLabel}
                                        />

                                        <button
                                            type="button"
                                            className="ppf-camera ppf-camera--sm"
                                            aria-label="Change photo"
                                            title="Change photo"
                                            disabled={uploadAvatar.isPending}
                                            onClick={pickPhoto}
                                        >
                                            <i className="ti ti-camera" />
                                        </button>
                                    </div>

                                    <div className="ppf-photo-text">
                                        <strong>Profile Photo</strong>
                                        <small>JPG, PNG or WebP. Up to 2MB.</small>

                                        <div className="ppf-photo-actions">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                icon="ti ti-upload"
                                                className="ppf-btn-accent"
                                                loading={uploadAvatar.isPending}
                                                onClick={pickPhoto}
                                            >
                                                {user?.avatar_url ? 'Change Photo' : 'Upload Photo'}
                                            </Button>

                                            {user?.avatar_url && (
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    icon="ti ti-trash"
                                                    className="ppf-btn-quiet"
                                                    loading={deleteAvatar.isPending}
                                                    onClick={() => deleteAvatar.mutate()}
                                                >
                                                    Remove
                                                </Button>
                                            )}
                                        </div>
                                    </div>
                                </div>

                                <form onSubmit={onProfileSubmit} noValidate className="ppf-personal-form">
                                    <FormError message={profileForm.formState.errors.root?.message} />

                                    <ProfileField
                                        name="name"
                                        label="Full Name"
                                        icon="ti ti-user"
                                        autoComplete="name"
                                        register={profileForm.register}
                                        errors={profileForm.formState.errors}
                                    />

                                    <ProfileField
                                        name="email"
                                        label="Email Address"
                                        icon="ti ti-mail"
                                        type="email"
                                        autoComplete="email"
                                        register={profileForm.register}
                                        errors={profileForm.formState.errors}
                                    />

                                    <div className="ppf-form-end">
                                        <Button
                                            type="submit"
                                            icon="ti ti-check"
                                            loading={profileForm.formState.isSubmitting}
                                        >
                                            Save Changes
                                        </Button>
                                    </div>
                                </form>
                            </div>
                        </section>

                        <section className="ppf-section" onFocusCapture={() => setSection('security')}>
                            <SectionHead
                                icon="ti ti-lock"
                                title="Security & Password"
                                description="Update your password to keep your account secure."
                            />

                            <form onSubmit={onPasswordSubmit} noValidate>
                                <FormError message={passwordForm.formState.errors.root?.message} />

                                <div className="ppf-security">
                                    <ShieldArt />

                                    <div className="ppf-security-fields">
                                        <ProfileField
                                            name="current_password"
                                            label="Current Password"
                                            icon="ti ti-key"
                                            type="password"
                                            placeholder="Enter current password"
                                            autoComplete="current-password"
                                            register={passwordForm.register}
                                            errors={passwordForm.formState.errors}
                                        />

                                        <ProfileField
                                            name="password"
                                            label="New Password"
                                            icon="ti ti-key"
                                            type="password"
                                            placeholder="Enter new password"
                                            autoComplete="new-password"
                                            register={passwordForm.register}
                                            errors={passwordForm.formState.errors}
                                        />

                                        <ProfileField
                                            name="password_confirmation"
                                            label="Confirm New Password"
                                            icon="ti ti-key"
                                            type="password"
                                            placeholder="Confirm new password"
                                            autoComplete="new-password"
                                            register={passwordForm.register}
                                            errors={passwordForm.formState.errors}
                                        />
                                    </div>

                                    <div className="ppf-security-side">
                                        <div className="ppf-rules">
                                            <h4>
                                                <i className="ti ti-shield-check" />
                                                Password Requirements
                                            </h4>

                                            <ul>
                                                {PASSWORD_RULES.map((rule) => (
                                                    <li
                                                        key={rule.label}
                                                        className={rule.met(newPassword) ? 'is-met' : undefined}
                                                    >
                                                        <i className="ti ti-check" />
                                                        {rule.label}
                                                    </li>
                                                ))}
                                            </ul>
                                        </div>

                                        <Button
                                            type="submit"
                                            icon="ti ti-key"
                                            className="ppf-update-password"
                                            loading={passwordForm.formState.isSubmitting}
                                        >
                                            Update Password
                                        </Button>
                                    </div>
                                </div>
                            </form>
                        </section>
                    </div>
                </div>
            </div>
        </div>
    );
}

function SectionHead({ icon, title, description }: { icon: string; title: string; description: string }) {
    return (
        <div className="ppf-section-head">
            <span className="ppf-tile" aria-hidden="true">
                <i className={icon} />
            </span>

            <div>
                <h3>{title}</h3>
                <p>{description}</p>
            </div>
        </div>
    );
}

/** A required input with its icon inside, and a show/hide eye on passwords. */
function ProfileField<T extends FieldValues>({
    name,
    label,
    icon,
    type = 'text',
    placeholder,
    autoComplete,
    register,
    errors,
}: {
    name: Path<T>;
    label: string;
    icon: string;
    type?: string;
    placeholder?: string;
    autoComplete?: string;
    register: UseFormRegister<T>;
    errors: FieldErrors<T>;
}) {
    const [revealed, setRevealed] = useState(false);
    const isPassword = type === 'password';
    const error: string | undefined = get(errors, name)?.message;

    return (
        <div className="ppf-field">
            <label className="form-label" htmlFor={name}>
                {label}
                <span className="text-danger ms-1">*</span>
            </label>

            <div className={cn('ppf-input', isPassword && 'ppf-input--eye')}>
                <i className={cn(icon, 'ppf-input-icon')} aria-hidden="true" />

                <input
                    id={name}
                    type={isPassword && revealed ? 'text' : type}
                    placeholder={placeholder}
                    autoComplete={autoComplete}
                    className={cn('form-control', error && 'is-invalid')}
                    {...register(name)}
                />

                {isPassword && (
                    <button
                        type="button"
                        className="ppf-eye"
                        aria-label={revealed ? `Hide ${label}` : `Show ${label}`}
                        aria-pressed={revealed}
                        onClick={() => setRevealed((value) => !value)}
                    >
                        <i className={revealed ? 'ti ti-eye' : 'ti ti-eye-off'} />
                    </button>
                )}
            </div>

            {error && <div className="invalid-feedback d-block">{error}</div>}
        </div>
    );
}
