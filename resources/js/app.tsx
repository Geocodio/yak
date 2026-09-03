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

    if (status === 403) {
        toast.error('You are not allowed to do that.');

        return false;
    }

    if (status >= 500) {
        toast.error('Something went wrong on the server.');

        return false;
    }
});

router.on('networkError', () => {
    toast.error('Could not reach the server. Check your connection and try again.');

    return false;
});

/**
 * The method of the visit currently in flight. Validation errors on a form
 * submit are rendered inline by `useForm`, but a GET visit -- a filter, a
 * sort, a period switch -- has no form to render them, so without this the
 * request silently bounces back and the control looks dead.
 */
let inFlightMethod = 'get';

router.on('before', (event) => {
    inFlightMethod = event.detail.visit.method;
});

router.on('error', (event) => {
    if (inFlightMethod !== 'get') {
        return;
    }

    const message = Object.values(event.detail.errors ?? {}).flat()[0];

    if (typeof message === 'string' && message !== '') {
        toast.error(message);
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
