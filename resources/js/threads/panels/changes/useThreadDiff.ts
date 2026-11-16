import { useEffect } from 'react';
import type { ThreadData } from '@/types/threads';
import type { ThreadDiffSummary } from '@/types/thread-changes';
import { diff as childDiff } from '@/routes/threads/stack/children';
import { prefetchJson, useJson } from '../useJson';

const POLL_MILLISECONDS = 15_000;

/** Files past this many start collapsed so a large change does not fetch every diff at once. */
export const OPEN_BY_DEFAULT = 4;

export function patchUrl(threadId: string, fileId: number): string {
    return `/threads/${threadId}/diff/files/${fileId}`;
}

/**
 * The stored diff of a thread. Reloads when the thread's activity or runner
 * state moves (a turn ending), and polls while a turn runs. `reload` is the one
 * seam a realtime `diff.updated` event can call instead of the poll.
 */
export function useThreadDiff(thread: ThreadData, childId: string | null = null): { diff: ThreadDiffSummary | null; isLoading: boolean; isRefreshing: boolean; error: string | null; reload: () => void } {
    const { data, isLoading, isRefreshing, error, reload } = useJson<{ diff: ThreadDiffSummary | null }>(childId === null ? `/threads/${thread.id}/diff` : childDiff.url({ thread: thread.id, child: childId }));

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

    const diff = data?.diff ?? null;
    const diffThreadId = childId ?? thread.id;

    // The first open files' patches load with the diff, so their cards open with code.
    useEffect(() => {
        diff?.files
            .filter((file) => !file.isBinary && !file.isTooLarge)
            .slice(0, OPEN_BY_DEFAULT)
            .forEach((file) => void prefetchJson(patchUrl(diffThreadId, file.id)));
    }, [diff, diffThreadId]);

    return { diff, isLoading, isRefreshing, error, reload };
}
