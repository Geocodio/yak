let override: boolean | null = null;

/** Whether requests can work: the app's own word once it has given one, the browser's guess before. */
export function isBrowserOnline(): boolean {
    return override ?? navigator.onLine;
}

/** The app's network monitor wins over the browser's; the page's listeners hear it as an `online` or `offline` event. */
export function setEmbedOnline(online: boolean): void {
    override = online;
    window.dispatchEvent(new Event(online ? 'online' : 'offline'));
}
