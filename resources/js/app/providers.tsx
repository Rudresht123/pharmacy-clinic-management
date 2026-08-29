import type { ReactNode } from 'react';
import { QueryClientProvider } from '@tanstack/react-query';
import { Toaster } from 'react-hot-toast';
import { BrowserRouter } from 'react-router-dom';
import { queryClient } from '@/shared/api/queryClient';
import { AuthProvider } from '@/core/auth/AuthProvider';
import { LockProvider } from '@/core/auth/LockProvider';
import { ConfirmProvider } from '@/shared/hooks/useConfirm';
import { ImagePreviewProvider } from '@/shared/hooks/useImagePreview';
import { SettingsProvider } from '@/shared/hooks/useAppSettings';
import { FullPageLoaderHost } from '@/shared/components/ui/Loader';
import { BootGate } from './BootGate';

/**
 * Every cross-cutting provider, composed once.
 *
 * Router sits outermost so AuthProvider (and anything else) can navigate.
 */
export function Providers({ children }: { children: ReactNode }) {
    return (
        <BrowserRouter>
            {/* Outermost so the accent colour is applied before anything
                paints, including the auth screens. */}
            <SettingsProvider>
                <QueryClientProvider client={queryClient}>
                    <AuthProvider>
                        <ConfirmProvider>
                            {/* One viewer for the whole app — a table of fifty
                            rows should not mount fifty overlays. */}
                            <ImagePreviewProvider>
                                {/* Inside AuthProvider so it knows who is signed in,
                                and inside BootGate's tree so the lock covers the
                                app once it has rendered. */}
                                <LockProvider>
                                    <BootGate>{children}</BootGate>
                                </LockProvider>
                            </ImagePreviewProvider>

                            {/* The single loading overlay. Callers raise a hand
                            with <FullPageLoader />; this draws it, so two of
                            them can never stack. */}
                            <FullPageLoaderHost />

                            {/* Toasts render their own markup via lib/notify, so
                            the library's default styling is switched off. */}
                            <Toaster
                                position="top-right"
                                gutter={10}
                                containerStyle={{ top: 74, right: 20 }}
                                toastOptions={{ style: {}, className: '' }}
                            />
                        </ConfirmProvider>
                    </AuthProvider>
                </QueryClientProvider>
            </SettingsProvider>
        </BrowserRouter>
    );
}
