import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Providers } from './providers';
import { AppRoutes } from './router';
import { TenantProviders } from './tenant-providers';
import { TenantAppRoutes } from './tenant-router';

const container = document.getElementById('root');

if (!container) {
    throw new Error('Mount point #root was not found.');
}

/**
 * One bundle, two trees. `app.hms.local` (or plain localhost/127.0.0.1,
 * which is how the admin panel is still reached before hosts-file entries
 * exist) renders the admin panel exactly as before; any other
 * `*.hms.local` host is a tenant's own subdomain.
 */
function isTenantHost(hostname: string): boolean {
    const host = hostname.toLowerCase();

    if (host === 'app.hms.local' || host === 'localhost' || host === '127.0.0.1') {
        return false;
    }

    return host.endsWith('.hms.local');
}

const root = createRoot(container);

if (isTenantHost(window.location.hostname)) {
    root.render(
        <StrictMode>
            <TenantProviders>
                <TenantAppRoutes />
            </TenantProviders>
        </StrictMode>,
    );
} else {
    root.render(
        <StrictMode>
            <Providers>
                <AppRoutes />
            </Providers>
        </StrictMode>,
    );
}
