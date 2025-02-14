import './dark-mode-init.js';
import './bootstrap';
import '../css/app.css';

import { createRoot } from 'react-dom/client';
import { createInertiaApp } from '@inertiajs/react';
import { router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { Providers } from './Providers/HeroUIProvider';

// Function to apply dark mode based on localStorage
function applyDarkModeClass() {
    const savedTheme = localStorage.getItem('darkMode');
    if (savedTheme === 'false') {
        document.documentElement.classList.remove('dark');
    } else {
        document.documentElement.classList.add('dark');
    }
}

// Apply dark mode on initial load
applyDarkModeClass();

// Apply dark mode after each Inertia navigation
router.on('navigate', applyDarkModeClass);

createInertiaApp({
    title: (title) => `${title} - OR Analytics Platform`,
    resolve: (name) =>
        resolvePageComponent(`./Pages/${name}.jsx`, import.meta.glob('./Pages/**/*.jsx')),
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(
            <App {...props}>
                {({ Component, props }) => (
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
