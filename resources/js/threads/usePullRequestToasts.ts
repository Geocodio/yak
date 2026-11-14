import { toast } from '@geocodio/console-ui';
import { useEffect, useRef } from 'react';
import { pullRequestToast } from '@/threads/pullRequestToast';
import { requestPullRequest } from '@/threads/panels/changes/OpenPullRequestButton';
import type { ThreadData, ThreadPageData } from '@/types/threads';

/**
 * Tells the person how an Open PR they asked for ended, once it leaves the opening state: open with a link,
 * held for a co-owner, or failed with GitHub's reason and a retry. Every tab that saw it opening says so.
 */
export function usePullRequestToasts(thread: ThreadData, pullRequest: ThreadPageData['pullRequest'], publishWarning: boolean): void {
    const state = thread.pullRequestOpening?.state ?? null;
    const previous = useRef({ threadId: thread.id, state });

    useEffect(() => {
        const was = previous.current.threadId === thread.id ? previous.current.state : null;
        previous.current = { threadId: thread.id, state };

        const result = pullRequestToast(was, state, pullRequest, thread.pullRequestOpening?.error ?? null);

        if (result?.kind === 'open') {
            toast.success(`Pull request #${result.number} is open`, { description: 'Ready for review on GitHub.', action: { label: 'View PR', onClick: () => window.open(result.url, '_blank', 'noopener') } });
        } else if (result?.kind === 'held') {
            toast.info('A co-owner needs to approve this PR', { description: 'Yak opens it as soon as someone approves.' });
        } else if (result?.kind === 'failed') {
            toast.error('Could not open the pull request', {
                description: result.reason ?? undefined,
                durationMs: null,
                action: { label: 'Try again', onClick: () => requestPullRequest(thread.id, publishWarning) },
            });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [thread.id, state, pullRequest?.number]);
}
