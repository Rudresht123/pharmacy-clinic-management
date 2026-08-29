import { useEffect } from 'react';
import { useMutation } from '@tanstack/react-query';
import { notify } from '@/shared/utils/notify';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import { FormError, TextField } from '@/shared/components/form/Fields';
import { useApiForm } from '@/shared/components/form/useApiForm';
import { useAuth } from '@/core/auth/AuthProvider';
import { http } from '@/shared/api/http';
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
                    <Card title="Profile Information">
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
                    <Card title="Change Password">
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
