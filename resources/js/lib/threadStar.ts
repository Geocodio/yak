import { router } from '@inertiajs/react';
import { star } from '@/routes/threads';

/** Fired after a change that can move a thread between sidebar groups, so the list refetches at once. */
export const THREAD_LIST_REFRESH_EVENT = 'yak:thread-list-refresh';

export function refreshThreadList(): void {
    window.dispatchEvent(new Event(THREAD_LIST_REFRESH_EVENT));
}

export function toggleStar(threadId: string): void {
    router.post(star.url(threadId), {}, { preserveScroll: true, preserveState: true, onSuccess: refreshThreadList });
}
