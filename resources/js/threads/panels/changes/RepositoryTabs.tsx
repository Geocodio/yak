import { cn } from '@geocodio/console-ui';
import type { ThreadDiffRepository } from '@/types/thread-changes';

type Props = {
    repositories: ThreadDiffRepository[];
    totalFilesCount: number;
    selected: string | null;
    onSelect: (repo: string | null) => void;
};

function tabClass(isSelected: boolean, isContext: boolean): string {
    return cn(
        'cursor-pointer whitespace-nowrap rounded-chip border px-2.5 py-1 text-[12px]',
        isContext ? 'border-dashed border-hair' : 'border-hair',
        isSelected ? 'bg-app font-medium text-body' : 'text-muted hover:text-body',
    );
}

/** Sub-tabs of the Changes panel: every repository together, then one tab per repository. */
export function RepositoryTabs({ repositories, totalFilesCount, selected, onSelect }: Props) {
    return (
        <div className="flex flex-wrap gap-1" role="tablist" aria-label="Repository" data-testid="changes-repository-tabs">
            <button type="button" role="tab" aria-selected={selected === null} onClick={() => onSelect(null)} className={tabClass(selected === null, false)} data-testid="changes-tab-all">
                All repos {totalFilesCount}
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
                    {repository.repo.split('/').pop()} {repository.filesCount}
                </button>
            ))}
        </div>
    );
}
