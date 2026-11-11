import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { seen } from '@/routes/threads';

const THREAD_URL = /^\/threads\/(\d+)/;

/** Posts `threads.seen` once per thread id while its page stays open. */
export function useMarkThreadSeen(url: string): void {
    const postedId = useRef<number | null>(null);
    const threadId = Number(THREAD_URL.exec(url.split('?')[0])?.[1] ?? 0) || null;

    useEffect(() => {
        if (threadId === null) {
            postedId.current = null;
            return;
        }
        if (postedId.current === threadId) {
            return;
        }
        postedId.current = threadId;
        router.post(seen.url(threadId), {}, { preserveState: true, preserveScroll: true, only: [] });
    }, [threadId]);
}
