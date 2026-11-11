export type DeviceWidth = 'phone' | 'tablet' | 'desktop';

export type StepStatus = 'pending' | 'active' | 'done';

const WIDTHS: Record<DeviceWidth, number | string> = { phone: 390, tablet: 768, desktop: '100%' };

export function widthFor(device: DeviceWidth): number | string {
    return WIDTHS[device];
}

/**
 * Desktop Safari only: it blocks the third-party cookie the preview handshake needs inside an iframe.
 * iOS Safari keeps the iframe.
 */
export function isDesktopSafari(userAgent: string): boolean {
    return /Macintosh/.test(userAgent) && /Safari/.test(userAgent) && !/Chrome|Chromium|CriOS|FxiOS|Edg|OPR/.test(userAgent);
}

/** Statuses of the three wake steps: wake the sandbox, start the dev server, wait for the app to answer. */
export function stepStatuses(state: string): [StepStatus, StepStatus, StepStatus] {
    switch (state) {
        case 'sandbox_asleep':
        case 'waking_sandbox':
        case 'waiting_for_slot':
            return ['active', 'pending', 'pending'];
        case 'starting_dev_server':
            return ['done', 'active', 'pending'];
        case 'ready':
            return ['done', 'done', 'done'];
        default:
            return ['pending', 'pending', 'pending'];
    }
}

/** States that change by themselves, so the panel keeps asking the status endpoint. */
export function isTransitional(state: string): boolean {
    return state === 'sandbox_asleep' || state === 'waking_sandbox' || state === 'starting_dev_server' || state === 'waiting_for_slot';
}

/** The iframe address: the host's origin plus the path the person typed. */
export function addressFor(baseUrl: string, path: string): string {
    // Leading slashes collapse to one so `//other.test` cannot leave the preview host.
    const normalized = `/${path.replace(/^[/\\]+/, '')}`;

    return new URL(normalized, baseUrl).toString();
}
