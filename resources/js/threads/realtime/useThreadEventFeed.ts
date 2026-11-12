import { useEffect, useReducer, useRef } from 'react';
import type { ThreadEventEnvelope, ThreadEventPointer, ThreadEventState, Viewer } from '../../types/thread-events';
import { fetchEvent, fetchEventsAfter } from './catchUp';
import { type ThreadEventFeed } from './feedReducer.ts';
import { createCatchUpRunner, needsHttpCatchUp } from './catchUpRunner';
import { getEcho } from './echo';
import { pollWhileVisible } from './pollWhileVisible';
import { initialFeed, reduceFeed } from './feedReducer.ts';
import { findGap, initialEventState } from './reducer';

export { initialFeed, reduceFeed, type ThreadEventFeed } from './feedReducer.ts';

/** Live thread state plus the sequence of each transcript entry. */
export function useThreadEventFeed(threadId: number): ThreadEventFeed {
    const [feed, dispatchFeed] = useReducer(reduceFeed, undefined, initialFeed);
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
                lastCatchUpFailed = connection === 'offline';

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
            };
        }

        const connection = echo.connector.pusher.connection;

        const onStateChange = ({ current }: { current: string }): void => {
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
            if (needsHttpCatchUp({ hasCaughtUp, lastCatchUpFailed, socketState: connection.state })) {
                void runner.request();
            }
        });

        return () => {
            isActive = false;
            stopPolling();
            connection.unbind('state_change', onStateChange);
            echo.leave(`thread.${threadId}`);
            echo.leave(`thread-presence.${threadId}`);
        };
    }, [threadId]);

    return feed;
}
