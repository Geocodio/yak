import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import type { SharedProps } from '@/types/shared';
import { setEmbedActive, type PageMessage } from './bridge';
import { cardActionOf } from './cardActions';
import { useEmbed, useEmbedMessage, useEmbedReady } from './useEmbed';

/** One script error or failed request tells the app; a flood of them does not. */
const ERROR_INTERVAL_MS = 10_000;

type ThreadPage = SharedProps & { thread?: { id: string; title: string }; card?: string | null; isLegacy?: boolean };

/** Wires the embedded page to the app: what it asks of the app, and how the app drives it. Draws nothing. */
export function EmbedBridge() {
    const embed = useEmbed();
    const { props } = usePage<ThreadPage>();
    const threadId = props.thread?.id ?? null;
    const card = useRef<string | null>(null);
    card.current = props.card ?? null;
    const previousThreadId = useRef<string | null>(null);

    useEmbedReady(threadId === null ? null : embed, threadId ?? '', props.thread?.title ?? '', props.isLegacy === true);

    useEffect(() => {
        if (embed !== null && threadId !== null && previousThreadId.current !== null && previousThreadId.current !== threadId) {
            embed.post({ type: 'threadChanged', threadId, previousThreadId: previousThreadId.current });
        }
        previousThreadId.current = threadId;
    }, [embed, threadId]);

    useEmbedMessage('open', (message) => {
        router.visit(`/app/embed/threads/${message.threadId}`, {
            showProgress: false,
            onFinish: () => {
                if (message.fragment !== undefined) {
                    focusFragment(message.fragment);
                }
            },
        });
    });
    useEmbedMessage('focus', (message) => focusFragment(message.fragment));
    useEmbedMessage('refresh', () => router.reload({ showProgress: false }));

    useEffect(() => {
        if (embed === null) {
            return;
        }
        setEmbedActive(true);
        let pending: { card: string; action: string; ok: boolean } | null = null;
        let lastErrorAt = 0;
        const reportError = (kind: 'http' | 'network' | 'script', status?: number) => {
            if (Date.now() - lastErrorAt >= ERROR_INTERVAL_MS) {
                lastErrorAt = Date.now();
                embed.post({ type: 'error', kind, ...(status === undefined ? {} : { status }) });
            }
        };
        const onScriptError = () => reportError('script');

        const stops = [
            router.on('before', (event) => {
                const visit = event.detail.visit;
                const url = new URL(String(visit.url), window.location.href);

                if (visit.method === 'get' && !(url.origin === window.location.origin && url.pathname.startsWith('/app/embed/'))) {
                    embed.post({ type: 'link', url: url.href });

                    return false;
                }

                const action = cardActionOf(visit.method, url.pathname);
                pending = action === null || card.current === null ? null : { card: card.current, action, ok: true };
            }),
            router.on('error', () => {
                pending &&= { ...pending, ok: false };
            }),
            router.on('success', (event) => {
                const flash = (event.detail.page.props as Partial<SharedProps>).flash;
                pending &&= flash?.error ? { ...pending, ok: false } : pending;
            }),
            router.on('httpException', (event) => {
                const status = event.detail.response.status;
                pending &&= { ...pending, ok: false };

                if (status === 401 || status === 419) {
                    embed.post({ type: 'auth', state: 'expired' });

                    return false;
                }
                if (status >= 500) {
                    reportError('http', status);
                }
            }),
            router.on('networkError', () => {
                pending &&= { ...pending, ok: false };
                reportError('network');
            }),
            router.on('finish', () => {
                if (pending !== null) {
                    embed.post({ type: 'cardAnswered', card: pending.card as Extract<PageMessage, { type: 'cardAnswered' }>['card'], action: pending.action, ok: pending.ok });
                    pending = null;
                }
            }),
        ];
        window.addEventListener('error', onScriptError);

        return () => {
            setEmbedActive(false);
            stops.forEach((stop) => stop());
            window.removeEventListener('error', onScriptError);
        };
    }, [embed]);

    return null;
}

/** Sets the address fragment, which `useFragmentScroll` follows; the same fragment again scrolls again. */
function focusFragment(fragment: string): void {
    if (window.location.hash === `#${fragment}`) {
        window.dispatchEvent(new HashChangeEvent('hashchange'));
    } else {
        window.location.hash = fragment;
    }
}
