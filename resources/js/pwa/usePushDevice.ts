import { router } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import { store } from '@/routes/settings/notifications/devices';

function decodeKey(base64Url: string): Uint8Array<ArrayBuffer> {
    const padded = (base64Url + '='.repeat((4 - (base64Url.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(padded);
    const bytes = new Uint8Array(new ArrayBuffer(raw.length));

    for (let index = 0; index < raw.length; index++) {
        bytes[index] = raw.charCodeAt(index);
    }

    return bytes;
}

async function currentSubscription(): Promise<PushSubscription | null> {
    if (typeof navigator === 'undefined' || !('serviceWorker' in navigator) || typeof PushManager === 'undefined') {
        return null;
    }

    try {
        const registration = await navigator.serviceWorker.ready;

        return await registration.pushManager.getSubscription();
    } catch {
        return null;
    }
}

export type SubscribeResult = 'subscribed' | 'denied' | 'unsupported' | 'failed';

/** Subscribes this browser to Web Push and registers it with the server; every browser API is feature-detected. */
export function usePushDevice(vapidPublicKey: string | null) {
    const [endpoint, setEndpoint] = useState<string | null>(null);

    useEffect(() => {
        let isActive = true;
        void currentSubscription().then((subscription) => isActive && setEndpoint(subscription?.endpoint ?? null));

        return () => {
            isActive = false;
        };
    }, []);

    const subscribe = useCallback(async (label: string): Promise<SubscribeResult> => {
        if (vapidPublicKey === null || typeof Notification === 'undefined' || !('serviceWorker' in navigator) || typeof PushManager === 'undefined') {
            return 'unsupported';
        }

        try {
            if ((await Notification.requestPermission()) !== 'granted') {
                return 'denied';
            }

            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: decodeKey(vapidPublicKey) });
            const json = subscription.toJSON();

            setEndpoint(subscription.endpoint);
            router.post(store.url(), { endpoint: subscription.endpoint, keys: { p256dh: json.keys?.p256dh ?? '', auth: json.keys?.auth ?? '' }, contentEncoding: 'aes128gcm', label }, { preserveScroll: true });

            return 'subscribed';
        } catch {
            return 'failed';
        }
    }, [vapidPublicKey]);

    const unsubscribe = useCallback(async (): Promise<void> => {
        const subscription = await currentSubscription();
        await subscription?.unsubscribe().catch(() => false);
        setEndpoint(null);
    }, []);

    const isCurrentDevice = useCallback((candidate: string | null): boolean => candidate !== null && endpoint !== null && candidate === endpoint, [endpoint]);

    return { endpoint, subscribe, unsubscribe, isCurrentDevice };
}
