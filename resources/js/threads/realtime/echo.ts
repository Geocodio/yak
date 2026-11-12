import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

let instance: Echo<'reverb'> | null = null;
let hasLoggedMissingKey = false;

type EchoEnv = { VITE_REVERB_APP_KEY?: string; DEV?: boolean };

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * Lazy singleton: one websocket per browser tab, shared by every thread subscription.
 * Returns null when no app key is configured; callers fall back to HTTP catch-up polling.
 */
export function getEcho(env: EchoEnv = import.meta.env): Echo<'reverb'> | null {
    const key = env.VITE_REVERB_APP_KEY?.trim();

    if (!key) {
        if (env.DEV && !hasLoggedMissingKey) {
            hasLoggedMissingKey = true;
            console.info('Realtime disabled: VITE_REVERB_APP_KEY is empty; using HTTP catch-up polling.');
        }

        return null;
    }

    if (instance === null) {
        const isSecure = window.location.protocol === 'https:';
        const port = Number(import.meta.env.VITE_REVERB_PORT) || Number(window.location.port) || (isSecure ? 443 : 80);

        (window as unknown as { Pusher: typeof Pusher }).Pusher = Pusher;

        instance = new Echo({
            broadcaster: 'reverb',
            key,
            wsHost: import.meta.env.VITE_REVERB_HOST || window.location.hostname,
            wsPort: port,
            wssPort: port,
            forceTLS: isSecure,
            enabledTransports: ['ws', 'wss'],
            // A stalled link keeps a dead socket open; ping early so the feed falls back to HTTP within seconds.
            activityTimeout: 10000,
            pongTimeout: 8000,
            authEndpoint: '/broadcasting/auth',
            auth: { headers: { 'X-XSRF-TOKEN': xsrfToken(), Accept: 'application/json' } },
        });
        // Conventional global handle; browser tests drive the connection through it.
        (window as unknown as { Echo: unknown }).Echo = instance;
    }

    return instance;
}
