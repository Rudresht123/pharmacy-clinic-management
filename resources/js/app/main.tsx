import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Providers } from './providers';
import { AppRoutes } from './router';

const container = document.getElementById('root');

if (!container) {
    throw new Error('Mount point #root was not found.');
}

createRoot(container).render(
    <StrictMode>
        <Providers>
            <AppRoutes />
        </Providers>
    </StrictMode>,
);
