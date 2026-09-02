import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import { ComponentType, StrictMode } from 'react';

const appName = 'Yak';

const pages = import.meta.glob<{ default: ComponentType }>('./pages/**/*.tsx');

createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),
    resolve: (name) => pages[`./pages/${name}.tsx`]().then((module) => module.default),
    setup({ el, App, props }) {
        createRoot(el).render(
            <StrictMode>
                <App {...props} />
            </StrictMode>,
        );
    },
    progress: { color: '#503ba3' },
});
