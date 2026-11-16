import type { ThreadEventState } from '../../types/thread-events';
import { type EventAction, initialEventState, reduceEvents } from './reducer.ts';

export type ThreadEventFeed = { state: ThreadEventState; entrySequences: number[]; entryTimes: string[] };

export function initialFeed(): ThreadEventFeed {
    return { state: initialEventState(), entrySequences: [], entryTimes: [] };
}

/**
 * Runs `reduceEvents` unchanged and records the envelope sequence of every entry it appended.
 * `entrySequences[i]` is the sequence of `state.entries[i]` and `entryTimes[i]` its time; an entry the reducer dropped records nothing.
 */
export function reduceFeed(feed: ThreadEventFeed, action: EventAction): ThreadEventFeed {
    if (action.kind !== 'events') {
        const state = reduceEvents(feed.state, action);

        return action.kind === 'reset' ? { state, entrySequences: [], entryTimes: [] } : { state, entrySequences: feed.entrySequences, entryTimes: feed.entryTimes };
    }

    let { state, entrySequences, entryTimes } = feed;

    for (const event of action.events) {
        const before = state.entries.length;
        state = reduceEvents(state, { kind: 'events', events: [event] });

        if (state.entries.length > before) {
            entrySequences = [...entrySequences, event.sequence];
            entryTimes = [...entryTimes, event.createdAt];
        }
    }

    if (action.latestSequence !== undefined) {
        state = reduceEvents(state, { kind: 'events', events: [], latestSequence: action.latestSequence });
    }

    return { state, entrySequences, entryTimes };
}
