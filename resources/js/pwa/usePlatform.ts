import { useEffect, useState } from 'react';

export type PlatformState = {
    isIos: boolean;
    isStandalone: boolean;
    isPushSupported: boolean;
    permission: NotificationPermission | 'unsupported';
};

export function readPlatform(): PlatformState {
    if (typeof window === 'undefined' || typeof navigator === 'undefined') {
        return { isIos: false, isStandalone: false, isPushSupported: false, permission: 'unsupported' };
    }

    const isIos = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    const isStandalone = window.matchMedia?.('(display-mode: standalone)').matches === true || (navigator as Navigator & { standalone?: boolean }).standalone === true;
    const isPushSupported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

    return { isIos, isStandalone, isPushSupported, permission: 'Notification' in window ? Notification.permission : 'unsupported' };
}

/** Browser capabilities after mount, so server and first client render agree. */
export function usePlatform(): PlatformState & { refresh: () => void } {
    const [state, setState] = useState<PlatformState>({ isIos: false, isStandalone: false, isPushSupported: false, permission: 'unsupported' });
    const refresh = () => setState(readPlatform());

    useEffect(refresh, []);

    return { ...state, refresh };
}
