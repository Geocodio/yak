import { useEffect } from 'react';
import type { ThreadData } from '@/types/threads';
import type { ThreadDiffSummary } from '@/types/thread-changes';
import { diff as childDiff } from '@/routes/threads/stack/children';
import { useJson } from '../useJson';

const POLL_MILLISECONDS = 15_000;

/**
 * The stored diff of a thread. Reloads when the thread's activity or runner
 * state moves (a turn ending), and polls while a turn runs. `reload` is the one
 * seam a realtime `diff.updated` event can call instead of the poll.
 */
export function useThreadDiff(thread: ThreadData, childId: string | null = null): { diff: ThreadDiffSummary | null; isLoading: boolean; error: string | null; reload: () => void } {
    const { data, isLoading, error, reload } = useJson<{ diff: ThreadDiffSummary | null }>(childId === null ? `/threads/${thread.id}/diff` : childDiff.url({ thread: thread.id, child: childId }));

    useEffect(() => {
        reload();
    }, [thread.lastActivityAt, thread.runnerState, reload]);

    useEffect(() => {
        if (thread.runnerState !== 'busy') {
            return;
        }
        const interval = setInterval(reload, POLL_MILLISECONDS);
        return () => clearInterval(interval);
    }, [thread.runnerState, reload]);

    return { diff: data?.diff ?? null, isLoading, error, reload };
}
