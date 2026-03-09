import './bootstrap';
import '../css/app.css';

import { createRoot } from 'react-dom/client';
import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { Providers } from './Providers/HeroUIProvider';
import type { ReactNode } from 'react';

interface InertiaAppProps {
    Component: React.ComponentType<Record<string, unknown>>;
    props: Record<string, unknown>;
}

createInertiaApp({
    title: (title: string) => `${title} - OR Analytics Platform`,
    resolve: (name: string) =>
        resolvePageComponent(`./Pages/${name}.jsx`, import.meta.glob('./Pages/**/*.jsx')),
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(
            <App {...props}>
                {({ Component, props }: InertiaAppProps): ReactNode => (
                    <Providers>
                        <Component {...props} />
                    </Providers>
                )}
            </App>
        );
    },
    progress: {
        color: '#4B5563',
    },
});
