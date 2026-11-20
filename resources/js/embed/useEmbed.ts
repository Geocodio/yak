import { usePage } from '@inertiajs/react';
import { useEffect, useMemo, useSyncExternalStore } from 'react';
import type { UnsentMessage } from '@/threads/outbox/outbox';
import type { SharedProps } from '@/types/shared';
import { sendToApp, type AppMessage, type PageMessage } from './bridge';
import { feedProgressOf, subscribeFeedProgress } from './feedProgress';
import { embedOutboxOf, subscribeEmbedOutbox, visibleOutbox } from './outboxSource';

export type Embed = { bridge: number; post: (message: PageMessage) => boolean };

/** Set while the phone's web view shows the page: only the conversation and its cards are drawn, and `post` talks to the app. */
export function useEmbed(): Embed | null {
    const embed = usePage<SharedProps>().props.embed;

    return useMemo(() => (embed === null ? null : { ...embed, post: sendToApp }), [embed]);
}

/** Runs the handler for each message of this type the app sends. */
export function useEmbedMessage<T extends AppMessage['type']>(type: T, handler: (message: Extract<AppMessage, { type: T }>) => void): void {
    useEffect(() => {
        const listener = (event: Event) => {
            const message = (event as CustomEvent<AppMessage>).detail;

            if (message.type === type) {
                handler(message as Extract<AppMessage, { type: T }>);
            }
        };
        window.addEventListener('yak:embed', listener);

        return () => window.removeEventListener('yak:embed', listener);
    });
}

/** The app's outbox for the thread in the shape the page's unsent row takes, minus what the server already has. */
export function useEmbedUnsent(threadId: string, messages: { sendKey: string | null }[]): UnsentMessage[] {
    const items = useSyncExternalStore(subscribeEmbedOutbox, () => embedOutboxOf(threadId));

    return useMemo(() => visibleOutbox(items, messages).map((item) => ({ ...item, model: null, effort: null, files: [], refs: [] })), [items, messages]);
}

/** Tells the app the thread is on screen: once the live feed has caught up, or after a short wait when it has not. */
export function useEmbedReady(embed: Embed | null, threadId: string, title: string, isLegacy: boolean): void {
    useEffect(() => {
        if (embed === null) {
            return;
        }
        let isSent = false;
        const send = () => {
            if (!isSent) {
                isSent = true;
                embed.post({ type: 'ready', threadId, url: window.location.pathname + window.location.search, latestSequence: feedProgressOf(threadId).lastSequence, title });
            }
        };
        const unsubscribe = subscribeFeedProgress((progress) => progress.threadId === threadId && progress.isSettled && send());
        const timer = window.setTimeout(send, 3000);

        if (isLegacy || feedProgressOf(threadId).isSettled) {
            send();
        }

        return () => {
            unsubscribe();
            window.clearTimeout(timer);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [embed !== null, threadId]);
}
