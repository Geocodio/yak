import { createInertiaApp, router } from '@inertiajs/react';
import { toast } from '@geocodio/console-ui';
import { createRoot } from 'react-dom/client';
import { ComponentType, StrictMode } from 'react';

const appName = 'Yak';

const pages = import.meta.glob<{ default: ComponentType }>('./pages/**/*.tsx');

router.on('httpException', (event) => {
    const status = event.detail.response.status;

    if (status === 419) {
        toast.error('Your session expired. Reloading…');
        window.location.reload();

        return;
    }

    if (status >= 500) {
        toast.error('Something went wrong on the server.');

        return false;
    }
});

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
