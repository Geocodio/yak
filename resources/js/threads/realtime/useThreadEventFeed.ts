import { useEffect, useReducer, useRef } from 'react';
import type { ThreadEventEnvelope, ThreadEventPointer, ThreadEventState, Viewer } from '../../types/thread-events';
import { fetchEvent, fetchEventsAfter } from './catchUp';
import { type ThreadEventFeed } from './feedReducer.ts';
import { createCatchUpRunner } from './catchUpRunner';
import { getEcho } from './echo';
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
        dispatch({ kind: 'reset' });
        lastSequence.current = 0;

        const runner = createCatchUpRunner({
            fetchAfter: (after) => fetchEventsAfter(threadId, after),
            getLastSequence: () => lastSequence.current,
            onResult: ({ events, latestSequence }) => {
                if (isActive) {
                    dispatch({ kind: 'events', events, latestSequence });
                }
            },
            onStatus: (connection) => {
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
        const connection = echo.connector.pusher.connection;
        let wasDisconnected = false;

        const onStateChange = ({ current }: { current: string }): void => {
            if (current === 'unavailable' || current === 'disconnected' || current === 'failed') {
                wasDisconnected = true;
                dispatch({ kind: 'connection', connection: 'offline' });
            } else if (current === 'connected' && wasDisconnected) {
                wasDisconnected = false;
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

        return () => {
            isActive = false;
            connection.unbind('state_change', onStateChange);
            echo.leave(`thread.${threadId}`);
            echo.leave(`thread-presence.${threadId}`);
        };
    }, [threadId]);

    return feed;
}
