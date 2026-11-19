import { GitBranch, Layers } from 'lucide-react';
import { SourceLink } from '@/components/threads/header/SourceLink';
import { RepositoryAvatar } from '@/components/threads/RepositoryAvatar';
import type { ThreadPageData } from '@/types/threads';

type Props = {
    thread: ThreadPageData['thread'];
    diffTotals: ThreadPageData['diffTotals'];
    subThreadCount: number;
    /** Where the request came from; shown when a channel made it. */
    source?: ThreadPageData['request']['source'];
    /** Opens the Changes view. */
    onOpenChanges: () => void;
    /** Opens the Overview rail, where the sub-threads are listed. */
    onOpenOverview: () => void;
};

const CHANNEL_SOURCES = ['slack', 'linear', 'sentry', 'github', 'email'];

/** The repo line of the header: repository, branch, a diff count that opens Changes, and a sub-thread count. */
export function ThreadHeaderMeta({ thread, diffTotals, subThreadCount, source, onOpenChanges, onOpenOverview }: Props) {
    if (!thread.hasRepository) {
        return (
            <div className="mt-0.5 flex min-w-0 items-center gap-2 text-[12.5px] text-muted" data-testid="thread-header-meta">
                <span className="inline-flex shrink-0 items-center rounded-pill bg-warn-soft px-2 py-0.5 text-[12px] font-medium text-warn" data-testid="thread-no-repository-pill">No repository yet</span>
                {source !== undefined && CHANNEL_SOURCES.includes(source.key) && (
                    <span className="inline-flex min-w-0 shrink-0 items-center gap-1" data-testid="thread-header-source">
                        From
                        <SourceLink source={source} testId="thread-source-link" />
                    </span>
                )}
            </div>
        );
    }

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
            {source !== undefined && CHANNEL_SOURCES.includes(source.key) && (
                <span className="inline-flex min-w-0 shrink-0 items-center gap-1" data-testid="thread-header-source">
                    <span className="opacity-50" aria-hidden>/</span>
                    From
                    <SourceLink source={source} testId="thread-source-link" />
                </span>
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
