import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import type { SharedProps } from '@/types/shared';
import { reportAuthExpired, setEmbedActive, type PageMessage } from './bridge';
import { cardActionOf } from './cardActions';
import { useEmbed, useEmbedMessage, useEmbedPageReady, useEmbedReady } from './useEmbed';

/** One script error or failed request tells the app; a flood of them does not. */
const ERROR_INTERVAL_MS = 10_000;

type ThreadPage = SharedProps & { thread?: { id: string; title: string }; card?: string | null; isLegacy?: boolean; deployment?: { branch: string }; pr?: { repoSlug: string; number: number } };

/** What the app shows for a page that is not a thread, by page component. */
function pageTitleOf(component: string, props: ThreadPage): string {
    switch (component) {
        case 'Deployments/Show':
            return props.deployment?.branch ?? 'Deployment';
        case 'PrReviews/ForPr':
            return props.pr === undefined ? 'PR review' : `${props.pr.repoSlug}#${props.pr.number}`;
        case 'PrReviews/Index':
            return 'PR reviews';
        case 'Deployments/Index':
            return 'Deployments';
        default:
            return typeof document === 'undefined' ? '' : document.title;
    }
}

/** Wires the embedded page to the app: what it asks of the app, and how the app drives it. Draws nothing. */
export function EmbedBridge() {
    const embed = useEmbed();
    const { props, component } = usePage<ThreadPage>();
    const threadId = props.thread?.id ?? null;
    const card = useRef<string | null>(null);
    card.current = props.card ?? null;
    const previousThreadId = useRef<string | null>(null);

    useEmbedReady(threadId === null ? null : embed, threadId ?? '', props.thread?.title ?? '', props.isLegacy === true);
    useEmbedPageReady(threadId === null ? embed : null, pageTitleOf(component, props));
    useExternalAnchors(threadId === null && embed !== null, embed);

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
        // The card POST in flight, found again by its address; a refresh or any other visit during it leaves it alone.
        let pending: { card: string; action: string; ok: boolean; href: string } | null = null;
        const isPending = (visit: { url: URL | string }) => pending !== null && new URL(String(visit.url), window.location.href).href === pending.href;
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

                if (action !== null && card.current !== null) {
                    pending = { card: card.current, action, ok: true, href: url.href };
                }
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
                    reportAuthExpired();

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
            router.on('finish', (event) => {
                if (pending !== null && isPending(event.detail.visit)) {
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
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [embed !== null]);

    return null;
}

/**
 * On the pages without a thread, a plain anchor to a page of this origin outside `/app/embed/` (a relative link in
 * Markdown, a link in an empty state) posts `link` instead of loading a page the embed session cannot open.
 * Anchors inside `/app/embed/`, `target="_blank"` anchors, downloads and anchors an Inertia link already handled
 * keep their behavior.
 */
function useExternalAnchors(isActive: boolean, embed: ReturnType<typeof useEmbed>): void {
    useEffect(() => {
        if (!isActive || embed === null) {
            return;
        }
        const onClick = (event: MouseEvent) => {
            const anchor = event.target instanceof Element ? event.target.closest('a[href]') : null;

            if (event.defaultPrevented || anchor === null || anchor.hasAttribute('download') || anchor.getAttribute('target') === '_blank') {
                return;
            }
            const url = new URL((anchor as HTMLAnchorElement).href, window.location.href);

            if (url.origin === window.location.origin && !url.pathname.startsWith('/app/embed/') && embed.post({ type: 'link', url: url.href })) {
                event.preventDefault();
            }
        };
        document.addEventListener('click', onClick);

        return () => document.removeEventListener('click', onClick);
    }, [isActive, embed]);
}

/** Sets the address fragment, which `useFragmentScroll` follows; the same fragment again scrolls again. */
function focusFragment(fragment: string): void {
    if (window.location.hash === `#${fragment}`) {
        window.dispatchEvent(new HashChangeEvent('hashchange'));
    } else {
        window.location.hash = fragment;
    }
}
