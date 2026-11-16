import type { ThreadEventState } from '../../types/thread-events';
import { useThreadEventFeed } from './useThreadEventFeed';

/** Live thread state: stored history, streaming blocks, turn and thread state, and who is watching. */
export function useThreadEvents(threadId: string): ThreadEventState {
    return useThreadEventFeed(threadId).state;
}
