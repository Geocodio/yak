import type {
    ThreadEventEnvelope,
    ThreadEventPointer,
    ThreadEventState,
    Viewer,
} from '../../types/thread-events';

export type EventAction =
    | { kind: 'events'; events: ThreadEventEnvelope[]; latestSequence?: number }
    | { kind: 'viewers'; viewers: Viewer[] }
    | { kind: 'connection'; connection: ThreadEventState['connection'] }
    | { kind: 'reset'; state?: Partial<ThreadEventState> };

export function initialEventState(): ThreadEventState {
    return {
        entries: [],
        streamingBlocks: {},
        turn: null,
        thread: null,
        viewers: [],
        connection: 'connecting',
        lastSequence: 0,
    };
}

/** Where a catch-up must start when `incoming` skips past the next expected sequence. */
export function findGap(
    state: ThreadEventState,
    incoming: ThreadEventEnvelope | ThreadEventPointer,
): { fromAfter: number } | null {
    return incoming.sequence > state.lastSequence + 1 ? { fromAfter: state.lastSequence } : null;
}

function applyEvent(state: ThreadEventState, event: ThreadEventEnvelope): ThreadEventState {
    const next = { ...state, lastSequence: event.sequence };

    switch (event.type) {
        case 'delta': {
            const data = event.data as ThreadEventEnvelope<'delta'>['data'];
            const previous = state.streamingBlocks[data.blockKey]?.text ?? '';

            return { ...next, streamingBlocks: { ...state.streamingBlocks, [data.blockKey]: { kind: data.kind, text: previous + data.text } } };
        }
        case 'entry': {
            const entry = event.data as ThreadEventEnvelope<'entry'>['data'];

            if (entry.role !== 'assistant') {
                return { ...next, entries: [...state.entries, entry] };
            }

            const prefix = entry.messageId + ':';
            const streamingBlocks = Object.fromEntries(Object.entries(state.streamingBlocks).filter(([key]) => !key.startsWith(prefix)));

            return { ...next, entries: [...state.entries, entry], streamingBlocks };
        }
        case 'stream.reset':
            return { ...next, streamingBlocks: {} };
        case 'turn':
            return { ...next, turn: event.data as ThreadEventEnvelope<'turn'>['data'] };
        case 'thread':
            return { ...next, thread: event.data as ThreadEventEnvelope<'thread'>['data'] };
        default:
            return next;
    }
}

export function reduceEvents(state: ThreadEventState, action: EventAction): ThreadEventState {
    switch (action.kind) {
        case 'events': {
            let next = state;

            for (const event of action.events) {
                if (event.sequence > next.lastSequence) {
                    next = applyEvent(next, event);
                }
            }

            if (action.latestSequence !== undefined && action.latestSequence > next.lastSequence) {
                next = { ...next, lastSequence: action.latestSequence };
            }

            return next;
        }
        case 'viewers':
            return { ...state, viewers: action.viewers };
        case 'connection':
            return { ...state, connection: action.connection };
        case 'reset':
            return { ...initialEventState(), ...action.state };
    }
}
