import { useEffect, useRef } from 'react';
import type { ThreadListData } from '@/types/shell';
import { csrfToken } from '@/lib/csrfToken';
import { threadIdOfUrl } from '@/components/shell/threadUrl';
import { seen } from '@/routes/threads';


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
 * happens when a turn finishes while the person watches. It never posts while
 * another seen post is in flight. A row with an unread sub-thread stays unread
 * whatever is posted for it, so that row costs one request per thread, not one
 * per refresh.
 */
export function useKeepThreadSeen(threadId: string | null, data: ThreadListData | null): void {
    const postedForSubThreads = useRef<string | null>(null);

    useEffect(() => {
        if (threadId === null || data === null || pendingPosts > 0) {
            return;
        }
        const rows = [...data.starred, ...data.needsYou, ...data.threads].flatMap((row) => [row, ...row.children]);
        const row = rows.find((candidate) => candidate.id === threadId && candidate.isUnread);
        if (!row) {
            return;
        }
        if (row.children.some((child) => child.isUnread)) {
            if (postedForSubThreads.current === threadId) {
                return;
            }
            postedForSubThreads.current = threadId;
        }
        void postSeen(threadId);
    }, [threadId, data]);
}

/** Posts `threads.seen` once per thread id while its page stays open. */
export function useMarkThreadSeen(url: string): void {
    const postedId = useRef<string | null>(null);
    const threadId = threadIdOfUrl(url);

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
