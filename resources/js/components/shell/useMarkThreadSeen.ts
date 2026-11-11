import { useEffect, useRef } from 'react';
import { csrfToken } from '@/lib/csrfToken';
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
        // A plain request: the endpoint answers 204, which an Inertia visit reports as an invalid response.
        void fetch(seen.url(threadId), { method: 'POST', credentials: 'same-origin', headers: { 'X-XSRF-TOKEN': csrfToken() } }).catch(() => undefined);
    }, [threadId]);
}
