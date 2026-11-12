import { useEffect, useReducer, useRef, useState } from 'react';
import type { ThreadEventEnvelope, ThreadEventPointer, ThreadEventState, Viewer } from '../../types/thread-events';
import { fetchEvent, fetchEventsAfter } from './catchUp';
import { type ThreadEventFeed } from './feedReducer.ts';
import { createCatchUpRunner, linkKind, needsHttpCatchUp, type LinkState } from './catchUpRunner';
import { getEcho } from './echo';
import { pollWhileVisible } from './pollWhileVisible';
import { initialFeed, reduceFeed } from './feedReducer.ts';
import { findGap, initialEventState } from './reducer';

export { initialFeed, reduceFeed, type ThreadEventFeed } from './feedReducer.ts';

/** Live thread state plus the sequence of each transcript entry. */
export function useThreadEventFeed(threadId: number): ThreadEventFeed & { link: LinkState } {
    const [feed, dispatchFeed] = useReducer(reduceFeed, undefined, initialFeed);
    const [link, setLink] = useState<LinkState>({ kind: 'ok', since: null });
    const state = feed.state;
    const dispatch = dispatchFeed;
    const lastSequence = useRef(0);
    const viewersRef = useRef<Viewer[]>([]);
    viewersRef.current = state.viewers;
    lastSequence.current = state.lastSequence;

    useEffect(() => {
        let isActive = true;
        let hasCaughtUp = false;
        let lastCatchUpFailed = false;
        let hasSocket = false;
        let socketDownAt: number | null = null;

        // Requests work again while the socket still waits out its backoff; reconnect it now.
        let reconnectSocket = (): void => {};

        const refreshLink = (): void => {
            const kind = linkKind({ lastCatchUpFailed, isBrowserOnline: navigator.onLine, hasSocket, socketDownForMs: socketDownAt === null ? 0 : Date.now() - socketDownAt });

            if (isActive) {
                setLink((current) => (current.kind === kind ? current : { kind, since: kind === 'ok' ? null : Date.now() }));
            }
        };

        setLink({ kind: 'ok', since: null });
        window.addEventListener('online', refreshLink);
        window.addEventListener('offline', refreshLink);
        dispatch({ kind: 'reset' });
        lastSequence.current = 0;

        const runner = createCatchUpRunner({
            fetchAfter: (after) => fetchEventsAfter(threadId, after),
            getLastSequence: () => lastSequence.current,
            onResult: ({ events, latestSequence }) => {
                hasCaughtUp = true;
                if (isActive) {
                    dispatch({ kind: 'events', events, latestSequence });
                }
            },
            onStatus: (connection) => {
                if (connection !== 'catching-up') {
                    const hasRecovered = lastCatchUpFailed && connection === 'live';
                    lastCatchUpFailed = connection === 'offline';
                    refreshLink();

                    if (hasRecovered) {
                        reconnectSocket();
                    }
                }

                if (isActive) {
                    dispatch({ kind: 'connection', connection });
                }
            },
        });

        const onEvent = async (incoming: ThreadEventEnvelope | ThreadEventPointer): Promise<void> => {
            const current = { ...initialEventState(), lastSequence: lastSequence.current };

            if (findGap(current, incoming)) {
                await runner.request();

                return;
            }

            try {
                const event = 'pointer' in incoming ? await fetchEvent(threadId, incoming.sequence) : incoming;

                if (isActive) {
                    dispatch({ kind: 'events', events: [event] });
                }
            } catch {
                await runner.request();
            }
        };

        const echo = getEcho();

        if (echo === null) {
            void runner.request();
            const stopPolling = pollWhileVisible(() => void runner.request());

            return () => {
                isActive = false;
                stopPolling();
                window.removeEventListener('online', refreshLink);
                window.removeEventListener('offline', refreshLink);
            };
        }

        const connection = echo.connector.pusher.connection;
        hasSocket = true;
        reconnectSocket = () => {
            if (connection.state !== 'connected') {
                echo.connector.pusher.disconnect();
                echo.connector.pusher.connect();
            }
        };
        socketDownAt = connection.state === 'connected' ? null : Date.now();

        const onStateChange = ({ current }: { current: string }): void => {
            socketDownAt = current === 'connected' ? null : (socketDownAt ?? Date.now());
            refreshLink();

            if (current === 'unavailable' || current === 'disconnected' || current === 'failed') {
                dispatch({ kind: 'connection', connection: 'offline' });
            } else if (current === 'connected') {
                // Every reconnect can hide missed events, also a fast one that never reported the socket as unavailable.
                void runner.request();
            }
        };

        connection.bind('state_change', onStateChange);

        echo.private(`thread.${threadId}`).listen('.thread.event', onEvent);
        echo.join(`thread-presence.${threadId}`)
            .here((viewers: Viewer[]) => dispatch({ kind: 'viewers', viewers }))
            .joining((viewer: Viewer) => dispatch({ kind: 'viewers', viewers: [...viewersRef.current.filter((known) => known.id !== viewer.id), viewer] }))
            .leaving((viewer: Viewer) => dispatch({ kind: 'viewers', viewers: viewersRef.current.filter((known) => known.id !== viewer.id) }));

        void runner.request();
        const stopPolling = pollWhileVisible(() => {
            refreshLink();

            if (needsHttpCatchUp({ hasCaughtUp, lastCatchUpFailed, socketState: connection.state })) {
                void runner.request();
            }
        });

        return () => {
            isActive = false;
            stopPolling();
            window.removeEventListener('online', refreshLink);
            window.removeEventListener('offline', refreshLink);
            connection.unbind('state_change', onStateChange);
            echo.leave(`thread.${threadId}`);
            echo.leave(`thread-presence.${threadId}`);
        };
    }, [threadId]);

    return { ...feed, link };
}
