interface PollOptions {
    intervalMs?: number;
    isVisible?: () => boolean;
    schedule?: typeof setInterval;
    clear?: typeof clearInterval;
}

/** Calls `request` every interval while the page is visible, and once when it becomes visible again. Returns a stop function. */
export function pollWhileVisible(request: () => void, options: PollOptions = {}): () => void {
    const {
        intervalMs = 3000,
        isVisible = () => document.visibilityState === 'visible',
        schedule = setInterval,
        clear = clearInterval,
    } = options;

    const requestIfVisible = (): void => {
        if (isVisible()) {
            request();
        }
    };

    const timer = schedule(requestIfVisible, intervalMs);
    const hasDocument = typeof document !== 'undefined';

    if (hasDocument) {
        document.addEventListener('visibilitychange', requestIfVisible);
    }

    return () => {
        clear(timer);

        if (hasDocument) {
            document.removeEventListener('visibilitychange', requestIfVisible);
        }
    };
}
