import { useEffect, type ReactNode } from 'react';
import { QueryClientProvider } from '@tanstack/react-query';
import { Toaster } from 'react-hot-toast';
import { BrowserRouter } from 'react-router-dom';
import { queryClient } from '@/shared/api/queryClient';
import { TenantAuthProvider, useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { ConfirmProvider } from '@/shared/hooks/useConfirm';
import { SettingsProvider } from '@/shared/hooks/useAppSettings';
import { FullPageLoaderHost } from '@/shared/components/ui/Loader';

const LOADER_ID = 'app-loader';
/** Matches the .hospital-loader opacity transition in style.min.css. */
const FADE_MS = 350;

/**
 * Hands over from the server-rendered boot loader to the tenant React tree —
 * a small copy of app/BootGate.tsx swapped onto useTenantAuth(), since that
 * one is wired to the platform AuthProvider specifically.
 */
function TenantBootGate({ children }: { children: ReactNode }) {
    const { initialising } = useTenantAuth();

    useEffect(() => {
        if (initialising) {
            return;
        }

        const loader = document.getElementById(LOADER_ID);

        if (!loader) {
            return;
        }

        loader.classList.add('hide');

        const timer = window.setTimeout(() => loader.remove(), FADE_MS);

        return () => window.clearTimeout(timer);
    }, [initialising]);

    if (initialising) {
        return null;
    }

    return <>{children}</>;
}

/**
 * Every cross-cutting provider an organization's workspace actually needs.
 *
 * Still smaller than app/providers.tsx: no LockProvider (it is built on the
 * platform AuthProvider) and no ImagePreviewProvider (nothing here opens the
 * viewer yet). SettingsProvider and ConfirmProvider are both generic, and
 * the shared AppShell needs them — appearance settings and the log-out
 * confirmation respectively.
 */
export function TenantProviders({ children }: { children: ReactNode }) {
    return (
        <BrowserRouter>
            <SettingsProvider>
                <QueryClientProvider client={queryClient}>
                    <TenantAuthProvider>
                        <ConfirmProvider>
                            <TenantBootGate>{children}</TenantBootGate>
                        </ConfirmProvider>
                    </TenantAuthProvider>

                    <FullPageLoaderHost />

                    <Toaster
                        position="top-right"
                        gutter={10}
                        containerStyle={{ top: 74, right: 20 }}
                        toastOptions={{ style: {}, className: '' }}
                    />
                </QueryClientProvider>
            </SettingsProvider>
        </BrowserRouter>
    );
}
