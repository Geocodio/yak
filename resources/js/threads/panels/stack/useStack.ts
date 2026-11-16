import { useEffect } from 'react';
import { show } from '@/routes/threads/stack';
import type { StackData } from '@/types/stack';
import type { ThreadData } from '@/types/threads';
import { useJson } from '../useJson';

const POLL_MILLISECONDS = 10_000;

/** The stack of a thread. Reloads when the thread's activity moves and polls while the page is visible. */
export function useStack(thread: ThreadData): { stack: StackData | null; isLoading: boolean; error: string | null; reload: () => void } {
    const { data, isLoading, error, reload } = useJson<StackData>(show.url(thread.id));

    useEffect(() => {
        reload();
    }, [thread.lastActivityAt, thread.runnerState, reload]);

    useEffect(() => {
        const interval = setInterval(() => {
            if (document.visibilityState === 'visible') {
                reload();
            }
        }, POLL_MILLISECONDS);

        return () => clearInterval(interval);
    }, [reload]);

    return { stack: data, isLoading, error, reload };
}
