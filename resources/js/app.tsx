import './bootstrap';
import '../css/app.css';

import { createRoot } from 'react-dom/client';
import { createInertiaApp } from '@inertiajs/react';
import { Providers } from './Providers/HeroUIProvider';
import type { ReactNode } from 'react';

interface InertiaAppProps {
    Component: React.ComponentType<Record<string, unknown>>;
    props: Record<string, unknown>;
}

createInertiaApp({
    title: (title: string) => `${title} - OR Analytics Platform`,
    resolve: (name: string) => {
        const pages = import.meta.glob('./Pages/**/*.{jsx,tsx}');
        const tsx = pages[`./Pages/${name}.tsx`];
        const jsx = pages[`./Pages/${name}.jsx`];
        const page = tsx ?? jsx;
        if (!page) {
            throw new Error(`Inertia page not found: ./Pages/${name}`);
        }
        return page();
    },
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
