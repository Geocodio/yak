import { router } from '@inertiajs/react';
import { refreshThreadList } from '@/lib/threadStar';
import { close, destroy } from '@/routes/threads';

/** The wording of the close and delete confirmations, shared by the thread header and the command palette. */
export function closeThreadConfirm(subThreadCount: number): { title: string; body: string; confirmLabel: string } {
    return {
        title: 'Close this thread?',
        body: `Its sandbox is destroyed${subThreadCount > 0 ? ` and its ${subThreadCount} ${subThreadCount === 1 ? 'sub-thread closes' : 'sub-threads close'} too` : ''}. You can still read it. It cannot be reopened.`,
        confirmLabel: 'Close thread',
    };
}

export const DELETE_THREAD_CONFIRM = {
    title: 'Delete this thread?',
    body: 'Messages, files and sub-threads are removed for good. The branch and any pull request on GitHub stay.',
    confirmLabel: 'Delete thread',
};

export function closeThread(threadId: string): void {
    router.post(close.url(threadId), {}, { preserveScroll: true, onFinish: refreshThreadList });
}

export function deleteThread(threadId: string): void {
    router.delete(destroy.url(threadId), { onFinish: refreshThreadList });
}
