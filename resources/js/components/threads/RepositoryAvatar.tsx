import { cn } from '@geocodio/console-ui';
import { repositoryInitials } from '@/lib/repositoryInitials';

/** A small rounded tile with the repository's initials. */
export function RepositoryAvatar({ repo, size = 'sm' }: { repo: string; size?: 'sm' | 'md' }) {
    return (
        <span
            aria-hidden="true"
            data-testid="repo-avatar"
            className={cn('inline-flex shrink-0 items-center justify-center rounded-[5px] bg-panel-2 font-semibold text-muted', size === 'sm' ? 'size-4 text-[9px]' : 'size-6 text-[11px]')}
        >
            {repositoryInitials(repo)}
        </span>
    );
}
