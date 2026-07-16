import './bootstrap';
import '../css/app.css';

import { createRoot } from 'react-dom/client';
import { createInertiaApp } from '@inertiajs/react';
import { Providers } from './Providers/HeroUIProvider';
import { NavigationProgress } from './Components/NavigationProgress';
import { registerLocalIconifyCollections } from './iconify-bundle';
import type { ReactNode } from 'react';

registerLocalIconifyCollections();

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
                        <NavigationProgress />
                        <Component {...props} />
                    </Providers>
                )}
            </App>
        );
    },
    // Inertia's bundled NProgress indicator emits invalid ARIA roles
    // (role="bar"/role="spinner") that fail WCAG 2.2 AA. It is disabled here in
    // favor of the accessible <NavigationProgress /> bar mounted above.
    progress: false,
});
