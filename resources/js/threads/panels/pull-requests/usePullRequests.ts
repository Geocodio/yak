import { useEffect } from 'react';
import { show } from '@/routes/threads/pull-requests';
import type { ThreadPullRequestsData } from '@/types/thread-pull-requests';
import type { ThreadData } from '@/types/threads';
import { useJson } from '../useJson';

const POLL_MILLISECONDS = 10_000;

/** The pull requests of a thread. Reloads when the thread's activity moves and polls while the page is visible. */
export function usePullRequests(thread: ThreadData): { data: ThreadPullRequestsData | null; isLoading: boolean; error: string | null; reload: () => void } {
    const { data, isLoading, error, reload } = useJson<ThreadPullRequestsData>(show.url(thread.id));

    useEffect(() => {
        reload();
    }, [thread.lastActivityAt, reload]);

    useEffect(() => {
        const interval = setInterval(() => {
            if (document.visibilityState === 'visible') {
                reload();
            }
        }, POLL_MILLISECONDS);

        return () => clearInterval(interval);
    }, [reload]);

    return { data, isLoading, error, reload };
}
