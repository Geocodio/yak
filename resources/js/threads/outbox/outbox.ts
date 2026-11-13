export type UnsentStatus = 'queued' | 'sending' | 'slow' | 'waiting' | 'refused';

/** A message the person sent that the server has not confirmed yet. */
export type UnsentMessage = { key: string; text: string; model: string | null; status: UnsentStatus; reason: string | null; createdAt: string };

export type PostOutcome = { kind: 'sent'; redirect?: string } | { kind: 'refused'; reason: string } | { kind: 'unreachable' };

type OutboxOptions = {
    post: (threadId: string, message: UnsentMessage, signal: AbortSignal) => Promise<PostOutcome>;
    /** Resolves once the page shows the delivered message, so the unsent copy can go without a gap. */
    onDelivered: (threadId: string, outcome: { redirect?: string }) => Promise<void>;
    newKey?: () => string;
    schedule?: (callback: () => void, milliseconds: number) => unknown;
    slowAfterMs?: number;
    retryAfterMs?: number;
};

const EMPTY: UnsentMessage[] = [];

/**
 * Per-thread send queues that live outside the page, so a send survives navigation between threads.
 * Each queue sends its head only: a message that waits or was refused holds the ones behind it, which keeps order.
 * A repeat reuses the message key, and the server takes each key once.
 */
export function createOutbox(options: OutboxOptions) {
    const { post, onDelivered, newKey = () => crypto.randomUUID(), schedule = (callback, milliseconds) => setTimeout(callback, milliseconds), slowAfterMs = 8000, retryAfterMs = 4000 } = options;
    const queues = new Map<string, UnsentMessage[]>();
    const inFlight = new Map<string, AbortController>();
    const listeners = new Set<() => void>();

    const write = (threadId: string, next: UnsentMessage[]): void => {
        queues.set(threadId, next);
        listeners.forEach((listener) => listener());
    };
    const patch = (threadId: string, key: string, change: Partial<UnsentMessage>): void =>
        write(threadId, (queues.get(threadId) ?? EMPTY).map((message) => (message.key === key ? { ...message, ...change } : message)));
    const drop = (threadId: string, key: string): void => write(threadId, (queues.get(threadId) ?? EMPTY).filter((message) => message.key !== key));
    const find = (threadId: string, key: string): UnsentMessage | undefined => queues.get(threadId)?.find((message) => message.key === key);

    const pump = async (threadId: string): Promise<void> => {
        const head = queues.get(threadId)?.[0];

        if (head === undefined || head.status !== 'queued' || inFlight.has(threadId)) {
            return;
        }

        const controller = new AbortController();
        inFlight.set(threadId, controller);
        patch(threadId, head.key, { status: 'sending', reason: null });
        schedule(() => find(threadId, head.key)?.status === 'sending' && inFlight.get(threadId) === controller && patch(threadId, head.key, { status: 'slow' }), slowAfterMs);

        const outcome = await post(threadId, head, controller.signal).catch((): PostOutcome => ({ kind: 'unreachable' }));
        inFlight.delete(threadId);

        if (find(threadId, head.key) === undefined) {
            // Cancelled while in flight.
        } else if (outcome.kind === 'sent') {
            await onDelivered(threadId, outcome).catch(() => {});
            drop(threadId, head.key);
        } else if (outcome.kind === 'refused') {
            patch(threadId, head.key, { status: 'refused', reason: outcome.reason });
        } else {
            patch(threadId, head.key, { status: 'waiting' });
            schedule(() => find(threadId, head.key)?.status === 'waiting' && retry(threadId, head.key), retryAfterMs);
        }

        void pump(threadId);
    };

    const retry = (threadId: string, key: string): void => {
        patch(threadId, key, { status: 'queued', reason: null });
        void pump(threadId);
    };

    return {
        subscribe(listener: () => void): () => void {
            listeners.add(listener);

            return () => listeners.delete(listener);
        },
        messages: (threadId: string): UnsentMessage[] => queues.get(threadId) ?? EMPTY,
        enqueue(threadId: string, text: string, model: string | null): void {
            write(threadId, [...(queues.get(threadId) ?? EMPTY), { key: newKey(), text, model, status: 'queued', reason: null, createdAt: new Date().toISOString() }]);
            void pump(threadId);
        },
        retry,
        /** Retries every message that waits for a connection, for when the browser reports the link is back. */
        retryWaiting(): void {
            queues.forEach((messages, threadId) => messages.filter((message) => message.status === 'waiting').forEach((message) => retry(threadId, message.key)));
        },
        /** Takes the message out of the queue and returns its text for the composer. */
        cancel(threadId: string, key: string): string {
            const message = find(threadId, key);

            if (message !== undefined && queues.get(threadId)?.[0]?.key === key) {
                inFlight.get(threadId)?.abort();
            }

            drop(threadId, key);
            void pump(threadId);

            return message?.text ?? '';
        },
    };
}
