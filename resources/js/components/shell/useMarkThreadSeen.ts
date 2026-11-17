import { useEffect, useRef } from 'react';
import type { ThreadListData } from '@/types/shell';
import { csrfToken } from '@/lib/csrfToken';
import { seen } from '@/routes/threads';

const THREAD_URL = /^\/threads\/([^/?#]+)/;

let pendingPosts = 0;

function postSeen(threadId: string): Promise<void> {
    pendingPosts += 1;
    // A plain request: the endpoint answers 204, which an Inertia visit reports as an invalid response.
    return fetch(seen.url(threadId), { method: 'POST', credentials: 'same-origin', headers: { 'X-XSRF-TOKEN': csrfToken() } })
        .then(() => undefined)
        .catch(() => undefined)
        .finally(() => {
            pendingPosts -= 1;
        });
}

/**
 * Posts `threads.seen` again when the open thread's row arrives unread, which
 * happens when a turn finishes while the person watches. It posts at most once
 * per list response and never while another seen post is in flight.
 */
export function useKeepThreadSeen(threadId: string | null, data: ThreadListData | null): void {
    const handled = useRef<ThreadListData | null>(null);

    useEffect(() => {
        if (threadId === null || data === null || handled.current === data) {
            return;
        }
        handled.current = data;
        const rows = [...data.starred, ...data.needsYou, ...data.threads].flatMap((row) => [row, ...row.children]);
        if (pendingPosts === 0 && rows.some((row) => row.id === threadId && row.isUnread)) {
            void postSeen(threadId);
        }
    }, [threadId, data]);
}

/** Posts `threads.seen` once per thread id while its page stays open. */
export function useMarkThreadSeen(url: string): void {
    const postedId = useRef<string | null>(null);
    const threadId = THREAD_URL.exec(url.split('?')[0])?.[1] ?? null;

    useEffect(() => {
        if (threadId === null) {
            postedId.current = null;
            return;
        }
        if (postedId.current === threadId) {
            return;
        }
        postedId.current = threadId;
        void postSeen(threadId);
    }, [threadId]);
}
