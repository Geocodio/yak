import { csrfToken } from '@/lib/csrfToken';
import { update } from '@/routes/threads/share';
import type { ThreadShareMode } from '@/types/thread-share';

/** Saves who can read a thread. Rejects when the server answers with an error. */
export async function updateThreadShare(threadId: string, mode: ThreadShareMode, userIds: number[]): Promise<void> {
    const response = await fetch(update.url(threadId), {
        method: 'PUT',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': csrfToken() },
        body: JSON.stringify({ mode, user_ids: mode === 'people' ? userIds : [] }),
    });

    if (!response.ok) {
        throw new Error(String(response.status));
    }
}
