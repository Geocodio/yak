import { GitBranch, Layers } from 'lucide-react';
import { RepositoryAvatar } from '@/components/threads/RepositoryAvatar';
import type { ThreadPageData } from '@/types/threads';

type Props = {
    thread: ThreadPageData['thread'];
    diffTotals: ThreadPageData['diffTotals'];
    subThreadCount: number;
    /** Opens the Changes view. */
    onOpenChanges: () => void;
    /** Opens the Overview rail, where the sub-threads are listed. */
    onOpenOverview: () => void;
};

/** The repo line of the header: repository, branch, a diff count that opens Changes, and a sub-thread count. */
export function ThreadHeaderMeta({ thread, diffTotals, subThreadCount, onOpenChanges, onOpenOverview }: Props) {
    return (
        <div className="mt-0.5 flex min-w-0 items-center gap-2 text-[12.5px] text-muted" data-testid="thread-header-meta">
            <RepositoryAvatar repo={thread.repo} variant="owner" />
            <span className="truncate">{thread.repo}</span>
            {thread.branch !== null && (
                <span className="hidden min-w-0 items-center gap-2 md:flex">
                    <span className="opacity-50" aria-hidden>/</span>
                    <GitBranch size={12} className="shrink-0" aria-hidden />
                    <code className="truncate font-mono text-[12px]" data-testid="thread-header-branch">{thread.branch}</code>
                </span>
            )}
            {diffTotals !== null && (
                <button type="button" onClick={onOpenChanges} title="Review changes" aria-label="Review changes" className="inline-flex shrink-0 cursor-pointer gap-1.5 rounded-[5px] px-1.5 py-px font-mono text-[12px] font-medium tabular-nums hover:bg-panel-2" data-testid="thread-header-diff">
                    <span className="text-ok">+{diffTotals.additions}</span>
                    <span className="text-fail">-{diffTotals.deletions}</span>
                </button>
            )}
            {subThreadCount > 0 && (
                <button type="button" onClick={onOpenOverview} className="inline-flex h-5 shrink-0 cursor-pointer items-center gap-1.5 rounded-pill bg-panel-2 px-2 text-[11.5px] font-medium text-muted hover:text-body" data-testid="thread-header-subthreads">
                    <Layers size={11} aria-hidden />
                    {subThreadCount} {subThreadCount === 1 ? 'sub-thread' : 'sub-threads'}
                </button>
            )}
        </div>
    );
}
