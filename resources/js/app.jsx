import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/react';
import { Providers as HeroUIProviders } from './Providers/HeroUIProvider';
import { ModeProvider } from './Contexts/ModeContext';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.jsx`,
            import.meta.glob('./Pages/**/*.jsx'),
        ),
    setup({ el, App, props }) {
        const root = createRoot(el);
        root.render(
            <HeroUIProviders>
                <ModeProvider>
                    <App {...props} />
                </ModeProvider>
            </HeroUIProviders>
        );
    },
    progress: {
        color: '#4B5563',
    },
});
