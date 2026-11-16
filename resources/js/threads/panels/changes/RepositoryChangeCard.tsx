import { Badge } from '@geocodio/console-ui';
import { RepositoryAvatar } from '@/components/threads/RepositoryAvatar';
import type { ThreadDiffFile, ThreadDiffRepository } from '@/types/thread-changes';
import { FileTree } from './FileTree';
import { buildFileTree } from './buildFileTree';

const ROLE_LABEL: Record<ThreadDiffRepository['role'], string> = { primary: 'Primary', changes: 'Changes', context: 'Context' };

type Props = {
    repository: ThreadDiffRepository;
    files: ThreadDiffFile[];
    primaryRepo: string;
    viewed: Set<string>;
    onOpen: (path: string) => void;
    onReview: (repo: string) => void;
};

/** One repository's changed files with its branch and pull request, shown in the All repos view. */
export function RepositoryChangeCard({ repository, files, primaryRepo, viewed, onOpen, onReview }: Props) {
    const hasPullRequest = repository.pullRequestUrl !== null && !repository.isThrowaway;

    return (
        <section className="rounded-card border border-hair" data-testid={`changes-repo-card-${repository.repo}`}>
            <header className="flex items-center gap-2 border-b border-hair px-3 py-2">
                <RepositoryAvatar repo={repository.repo} size="sm" />
                <span className="min-w-0 flex-1 truncate text-[13px] font-medium text-body">{repository.repo}</span>
                <Badge tone={repository.role === 'primary' ? 'accent' : 'neutral'}>{ROLE_LABEL[repository.role]}</Badge>
                <span className="shrink-0 text-[11.5px] tabular-nums">
                    <span className="text-ok">+{repository.additions}</span> <span className="text-fail">-{repository.deletions}</span>
                </span>
            </header>
            <div className="px-3 py-1">
                {files.length > 0 ? (
                    <FileTree nodes={buildFileTree(files, primaryRepo)} viewed={viewed} onOpen={onOpen} />
                ) : (
                    <p className="py-2 text-[12px] text-muted">No files changed</p>
                )}
            </div>
            <footer className="flex items-center justify-between gap-2 border-t border-hair px-3 py-2 text-[12px] text-muted">
                <span className="truncate">{repository.branch !== null ? `On ${repository.branch}` : hasPullRequest ? '' : 'No pull request'}</span>
                {hasPullRequest ? (
                    <a href={repository.pullRequestUrl ?? undefined} target="_blank" rel="noreferrer" className="shrink-0 text-accent-text hover:underline" data-testid={`changes-repo-review-${repository.repo}`}>
                        Review
                    </a>
                ) : (
                    <span className="flex shrink-0 items-center gap-2">
                        {repository.branch !== null && <span>No pull request</span>}
                        <button type="button" onClick={() => onReview(repository.repo)} className="cursor-pointer text-accent-text hover:underline" data-testid={`changes-repo-review-${repository.repo}`}>
                            Review
                        </button>
                    </span>
                )}
            </footer>
        </section>
    );
}
