export async function registerServiceWorker(): Promise<ServiceWorkerRegistration | null> {
    if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) {
        return null;
    }

    try {
        return await navigator.serviceWorker.register('/sw.js', { scope: '/' });
    } catch {
        return null;
    }
}
