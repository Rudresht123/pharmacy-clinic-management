import { useEffect, useRef } from 'react';
import { useMutation } from '@tanstack/react-query';
import { notify } from '@/shared/utils/notify';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import { FormError, TextField } from '@/shared/components/form/Fields';
import { useApiForm } from '@/shared/components/form/useApiForm';
import { useAuth } from '@/core/auth/AuthProvider';
import { http } from '@/shared/api/http';
import { initials } from '@/shared/utils/format';
import { authApi } from '@/core/auth/api';
import type { ApiResponse } from '@/shared/types/api';
import type { PlatformUser } from '@/core/auth/api';

interface ProfileValues {
    name: string;
    email: string;
}

interface PasswordValues {
    current_password: string;
    password: string;
    password_confirmation: string;
}

export default function ProfilePage() {
    const { user, setUser } = useAuth();
    const avatarInput = useRef<HTMLInputElement>(null);

    const profileForm = useApiForm<ProfileValues>({
        defaultValues: { name: '', email: '' },
    });

    const passwordForm = useApiForm<PasswordValues>({
        defaultValues: { current_password: '', password: '', password_confirmation: '' },
    });

    useEffect(() => {
        if (user) {
            profileForm.reset({ name: user.name, email: user.email });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [user]);

    const updateProfile = useMutation({
        mutationFn: async (values: ProfileValues) => {
            const { data } = await http.put<ApiResponse<PlatformUser>>(
                '/admin/auth/profile',
                values,
            );

            return data.data;
        },
        onSuccess: (updated) => {
            setUser(updated);
            notify.success('Profile updated');
        },
    });

    const updatePassword = useMutation({
        mutationFn: async (values: PasswordValues) => {
            await http.put('/admin/auth/password', values);
        },
        onSuccess: () => {
            passwordForm.reset({ current_password: '', password: '', password_confirmation: '' });
            notify.success('Password updated', {
                description: 'Use it the next time you sign in.',
            });
        },
    });

    const uploadAvatar = useMutation({
        mutationFn: authApi.uploadAvatar,
        onSuccess: (updated) => {
            setUser(updated);
            notify.success('Photo updated');
        },
    });

    const deleteAvatar = useMutation({
        mutationFn: authApi.deleteAvatar,
        onSuccess: (updated) => {
            setUser(updated);
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

    const onProfileSubmit = profileForm.handleSubmit(async (values) => {
        await profileForm.submit(values, updateProfile.mutateAsync);
    });

    const onPasswordSubmit = passwordForm.handleSubmit(async (values) => {
        await passwordForm.submit(values, updatePassword.mutateAsync);
    });

    return (
        <>
            <PageHeader
                title="My Profile"
                icon="ti ti-user-circle"
                tone="amber"
                crumbs={[{ label: 'Account' }, { label: 'Profile' }]}
            />

            <div className="row g-3">
                <div className="col-lg-6">
                    <Card
                        title="Profile Information"
                        icon="ti ti-id-badge-2"
                        description="Your photo, name and sign-in email."
                        className="h-100"
                    >
                        <div className="d-flex align-items-center gap-3 mb-4">
                            {user?.avatar_url ? (
                                <img
                                    src={user.avatar_url}
                                    alt=""
                                    className="rounded-circle flex-shrink-0"
                                    style={{ width: 64, height: 64, objectFit: 'cover' }}
                                />
                            ) : (
                                <span
                                    className="avatar rounded-circle bg-primary text-white d-flex align-items-center justify-content-center flex-shrink-0"
                                    style={{ width: 64, height: 64, fontSize: 22, fontWeight: 600 }}
                                >
                                    {user ? initials(user.name) : '?'}
                                </span>
                            )}

                            <div>
                                <div className="d-flex gap-2">
                                    <input
                                        ref={avatarInput}
                                        type="file"
                                        accept="image/png,image/jpeg,image/webp"
                                        className="d-none"
                                        onChange={onAvatarChosen}
                                    />

                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        loading={uploadAvatar.isPending}
                                        onClick={() => avatarInput.current?.click()}
                                    >
                                        {user?.avatar_url ? 'Replace photo' : 'Upload photo'}
                                    </Button>

                                    {user?.avatar_url && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            loading={deleteAvatar.isPending}
                                            onClick={() => deleteAvatar.mutate()}
                                        >
                                            Remove
                                        </Button>
                                    )}
                                </div>

                                <p className="text-muted fs-12 mb-0 mt-2">
                                    JPG, PNG or WebP. Up to 2MB.
                                </p>
                            </div>
                        </div>

                        <form onSubmit={onProfileSubmit} noValidate>
                            <FormError message={profileForm.formState.errors.root?.message} />

                            <TextField
                                name="name"
                                label="Name"
                                required
                                register={profileForm.register}
                                errors={profileForm.formState.errors}
                            />

                            <TextField
                                name="email"
                                label="Email"
                                type="email"
                                required
                                register={profileForm.register}
                                errors={profileForm.formState.errors}
                            />

                            <Button type="submit" loading={profileForm.formState.isSubmitting}>
                                Save Changes
                            </Button>
                        </form>
                    </Card>
                </div>

                <div className="col-lg-6">
                    <Card
                        title="Change Password"
                        icon="ti ti-lock"
                        description="Use something you don't use anywhere else."
                        className="h-100"
                    >
                        <form onSubmit={onPasswordSubmit} noValidate>
                            <FormError message={passwordForm.formState.errors.root?.message} />

                            <TextField
                                name="current_password"
                                label="Current Password"
                                type="password"
                                required
                                register={passwordForm.register}
                                errors={passwordForm.formState.errors}
                            />

                            <TextField
                                name="password"
                                label="New Password"
                                type="password"
                                required
                                register={passwordForm.register}
                                errors={passwordForm.formState.errors}
                            />

                            <TextField
                                name="password_confirmation"
                                label="Confirm New Password"
                                type="password"
                                required
                                register={passwordForm.register}
                                errors={passwordForm.formState.errors}
                            />

                            <Button type="submit" loading={passwordForm.formState.isSubmitting}>
                                Update Password
                            </Button>
                        </form>
                    </Card>
                </div>
            </div>
        </>
    );
}
