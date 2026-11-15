import type { ThreadEventState } from '../../types/thread-events';

/** One string that changes whenever the turn or the thread state behind the page props changes. */
export function livePropsSignature(threadId: string, events: Pick<ThreadEventState, 'turn' | 'thread'>): string {
    const { turn, thread } = events;

    return [threadId, turn?.turnId, turn?.state, thread?.status, thread?.sandboxState, thread?.runnerState, thread?.blockedReason, thread?.pullRequestOpening, thread?.ownerSide].join('|');
}
