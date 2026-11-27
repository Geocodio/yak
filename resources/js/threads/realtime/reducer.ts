import type {
    ThreadEventEnvelope,
    ThreadEventPointer,
    ThreadEventState,
    TranscriptEntry,
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
        turnStartSequence: null,
        thread: null,
        questionOpen: null,
        children: {},
        childResults: [],
        repositories: {},
        agents: {},
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

function blockKeyOf(entry: Extract<TranscriptEntry, { role: 'assistant' }>): string | null {
    return entry.blockIndex === undefined ? null : `${entry.messageId}:${entry.blockIndex}`;
}

/** A catch-up skipped events, so any block still open may have missed chunks. */
function markBlocksUnseen(state: ThreadEventState): ThreadEventState {
    return { ...state, streamingBlocks: Object.fromEntries(Object.entries(state.streamingBlocks).map(([key, block]) => [key, { ...block, seen: false }])) };
}

function applyEvent(state: ThreadEventState, event: ThreadEventEnvelope): ThreadEventState {
    const next = { ...state, lastSequence: event.sequence };

    switch (event.type) {
        case 'delta': {
            const data = event.data as ThreadEventEnvelope<'delta'>['data'];
            // A tail the runner held back can follow the block's final entry; the entry already holds it.
            if (state.entries.some((entry) => entry.role === 'assistant' && blockKeyOf(entry) === data.blockKey)) {
                return next;
            }

            const known = state.streamingBlocks[data.blockKey];
            // A block is shown only when every chunk of it arrived in order from its start.
            const isContiguous = data.offset === (known?.text.length ?? 0);
            const block = { kind: data.kind, text: (known?.text ?? '') + data.text, seen: (known?.seen ?? true) && isContiguous, startedAt: known?.startedAt ?? data.startedAt ?? event.createdAt };

            return { ...next, streamingBlocks: { ...state.streamingBlocks, [data.blockKey]: block } };
        }
        case 'entry': {
            const entry = event.data as ThreadEventEnvelope<'entry'>['data'];

            // An agent's own steps are read in its log, not in Claude's transcript.
            if (entry.agentId !== undefined) {
                return next;
            }

            if (entry.role !== 'assistant') {
                return { ...next, entries: [...state.entries, entry] };
            }

            // An entry with a block index closes only its own block; a later block of the message keeps streaming.
            const closes = (key: string): boolean => (entry.blockIndex === undefined ? key.startsWith(entry.messageId + ':') : key === `${entry.messageId}:${entry.blockIndex}`);
            const streamingBlocks = Object.fromEntries(Object.entries(state.streamingBlocks).filter(([key]) => !closes(key)));
            const replaced = entry.blockIndex === undefined ? -1 : state.entries.findIndex((known) => known.role === 'assistant' && blockKeyOf(known) === blockKeyOf(entry));
            // A cut-off copy never replaces a finished entry of the same block.
            if (replaced !== -1 && entry.cutOff === true && (state.entries[replaced] as typeof entry).cutOff !== true) {
                return { ...next, streamingBlocks };
            }

            const entries = replaced === -1 ? [...state.entries, entry] : state.entries.map((known, index) => (index === replaced ? entry : known));

            return { ...next, entries, streamingBlocks };
        }
        case 'block.cut': {
            const { blockKey, cutReason } = event.data as ThreadEventEnvelope<'block.cut'>['data'];

            return { ...next, entries: state.entries.map((entry) => (entry.role === 'assistant' && blockKeyOf(entry) === blockKey ? { ...entry, cutOff: true, cutReason: cutReason ?? 'stopped' } : entry)) };
        }
        case 'stream.reset':
            return { ...next, streamingBlocks: {} };
        case 'turn': {
            const turn = event.data as ThreadEventEnvelope<'turn'>['data'];
            const isStart = turn.state === 'running' && (state.turn === null || state.turn.turnId !== turn.turnId || state.turn.state !== 'running');

            // A turn that stopped running streams nothing more; its unfinished text arrives as stored entries.
            const streamingBlocks = turn.state === 'running' ? state.streamingBlocks : {};

            return { ...next, turn, turnStartSequence: isStart ? event.sequence : state.turnStartSequence, streamingBlocks };
        }
        case 'thread': {
            const data = event.data as ThreadEventEnvelope<'thread'>['data'];

            // Only the events a card change publishes carry the key; a turn's state event keeps the last one.
            return { ...next, thread: { ...data, showKey: data.showKey ?? next.thread?.showKey } };
        }
        case 'question':
            return { ...next, questionOpen: (event.data as ThreadEventEnvelope<'question'>['data']).open };
        case 'child': {
            const data = event.data as ThreadEventEnvelope<'child'>['data'];

            return { ...next, children: { ...state.children, [data.childThreadId]: { sequence: state.children[data.childThreadId]?.sequence ?? event.sequence, data } } };
        }
        case 'child.result': {
            const data = event.data as ThreadEventEnvelope<'child.result'>['data'];

            return state.childResults.some((result) => result.data.id === data.id) ? next : { ...next, childResults: [...state.childResults, { sequence: event.sequence, data: { ...data, at: event.createdAt } }] };
        }
        case 'repository': {
            const data = event.data as ThreadEventEnvelope<'repository'>['data'];

            return { ...next, repositories: { ...state.repositories, [data.repo]: { sequence: state.repositories[data.repo]?.sequence ?? event.sequence, data } } };
        }
        case 'agent.started':
        case 'agent.progress':
        case 'agent.finished': {
            const data = event.data as ThreadEventEnvelope<'agent.started'>['data'];

            return { ...next, agents: { ...state.agents, [data.id]: data } };
        }
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
                    next = applyEvent(event.sequence > next.lastSequence + 1 ? markBlocksUnseen(next) : next, event);
                }
            }

            if (action.latestSequence !== undefined && action.latestSequence > next.lastSequence) {
                next = { ...markBlocksUnseen(next), lastSequence: action.latestSequence };
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
