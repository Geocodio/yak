import { GitPullRequest } from 'lucide-react';
import type { ThreadPageData } from '@/types/threads';

type Props = {
    thread: ThreadPageData['thread'];
    diffTotals: ThreadPageData['diffTotals'];
    pullRequest: ThreadPageData['pullRequest'];
};

/** The repo line of the header with the diff totals and a link to the pull request. */
export function ThreadHeaderMeta({ thread, diffTotals, pullRequest }: Props) {
    return (
        <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-[12px] text-muted" data-testid="thread-header-meta">
            <span>{thread.repo}</span>
            {diffTotals !== null && (
                <span data-testid="thread-header-diff">
                    <span className="text-ok">+{diffTotals.additions}</span> <span className="text-fail">-{diffTotals.deletions}</span>
                </span>
            )}
            {pullRequest !== null && (
                <a href={pullRequest.url} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 rounded-chip border border-hair px-2 py-0.5 font-medium text-body hover:bg-panel-2" data-testid="thread-header-pr">
                    <GitPullRequest size={12} aria-hidden /> PR #{pullRequest.number}
                </a>
            )}
        </div>
    );
}
