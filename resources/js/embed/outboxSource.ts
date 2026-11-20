/** One message of the native outbox, as the app describes it. */
export type OutboxItem = { key: string; text: string; status: 'queued' | 'sending' | 'slow' | 'waiting' | 'refused'; reason: string | null; createdAt: string; attachmentCount: number };

const EMPTY: OutboxItem[] = [];
let current: { threadId: string; items: OutboxItem[] } = { threadId: '', items: EMPTY };
const listeners = new Set<() => void>();

/** Replaces the whole outbox, so the same message twice changes nothing. */
export function setEmbedOutbox(threadId: string, items: OutboxItem[]): void {
    current = { threadId, items };
    listeners.forEach((listener) => listener());
}

export function subscribeEmbedOutbox(listener: () => void): () => void {
    listeners.add(listener);

    return () => listeners.delete(listener);
}

export function embedOutboxOf(threadId: string): OutboxItem[] {
    return current.threadId === threadId ? current.items : EMPTY;
}

/** An item goes as soon as the thread's messages carry a row with its key: the server copy replaces it. */
export function visibleOutbox(items: OutboxItem[], messages: { sendKey: string | null }[]): OutboxItem[] {
    const confirmed = new Set(messages.map((message) => message.sendKey).filter((key) => key !== null));
    const visible = items.filter((item) => !confirmed.has(item.key));

    return visible.length === items.length ? items : visible;
}
