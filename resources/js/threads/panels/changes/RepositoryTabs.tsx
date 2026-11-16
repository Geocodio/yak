import { cn } from '@geocodio/console-ui';
import { RepositoryAvatar } from '@/components/threads/RepositoryAvatar';
import type { ThreadDiffRepository } from '@/types/thread-changes';

type Props = {
    repositories: ThreadDiffRepository[];
    totalFilesCount: number;
    selected: string | null;
    onSelect: (repo: string | null) => void;
};

function CountPill({ count }: { count: number }) {
    return <span className="rounded-pill bg-panel-2 px-1.5 text-[11px] tabular-nums text-muted" data-testid="changes-tab-count">{count}</span>;
}

function tabClass(isSelected: boolean, isContext: boolean): string {
    return cn(
        'inline-flex cursor-pointer items-center gap-1.5 whitespace-nowrap rounded-chip border px-2.5 py-1 text-[12px]',
        isContext ? 'border-dashed border-hair' : 'border-hair',
        isSelected ? 'bg-app font-medium text-body' : 'text-muted hover:text-body',
    );
}

/** Sub-tabs of the Changes panel: every repository together, then one tab per repository. */
export function RepositoryTabs({ repositories, totalFilesCount, selected, onSelect }: Props) {
    return (
        <div className="flex flex-wrap gap-1" role="tablist" aria-label="Repository" data-testid="changes-repository-tabs">
            <button type="button" role="tab" aria-selected={selected === null} onClick={() => onSelect(null)} className={tabClass(selected === null, false)} data-testid="changes-tab-all">
                All repos <CountPill count={totalFilesCount} />
            </button>
            {repositories.map((repository) => (
                <button
                    key={repository.repo}
                    type="button"
                    role="tab"
                    aria-selected={selected === repository.repo}
                    onClick={() => onSelect(repository.repo)}
                    className={tabClass(selected === repository.repo, repository.isThrowaway)}
                    data-testid={`changes-tab-${repository.repo}`}
                >
                    <RepositoryAvatar repo={repository.repo} size="sm" />
                    {repository.repo.split('/').pop()} <CountPill count={repository.filesCount} />
                </button>
            ))}
        </div>
    );
}
