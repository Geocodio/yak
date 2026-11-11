import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

let instance: Echo<'reverb'> | null = null;

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/** Lazy singleton: one websocket per browser tab, shared by every thread subscription. */
export function getEcho(): Echo<'reverb'> {
    if (instance === null) {
        const isSecure = window.location.protocol === 'https:';
        const port = Number(window.location.port) || (isSecure ? 443 : 80);

        (window as unknown as { Pusher: typeof Pusher }).Pusher = Pusher;

        instance = new Echo({
            broadcaster: 'reverb',
            key: import.meta.env.VITE_REVERB_APP_KEY,
            wsHost: window.location.hostname,
            wsPort: port,
            wssPort: port,
            forceTLS: isSecure,
            enabledTransports: ['ws', 'wss'],
            authEndpoint: '/broadcasting/auth',
            auth: { headers: { 'X-XSRF-TOKEN': xsrfToken(), Accept: 'application/json' } },
        });
    }

    return instance;
}
