import { cn } from '@geocodio/console-ui';
import { repositoryInitials, repositoryOwnerInitial } from '@/lib/repositoryInitials';

/**
 * A small rounded tile for a repository. By default it shows the repository's
 * initials; `variant="owner"` shows the owner's initial on slate, as the
 * composer chips do.
 */
export function RepositoryAvatar({ repo, size = 'sm', variant = 'initials' }: { repo: string; size?: 'sm' | 'md'; variant?: 'initials' | 'owner' }) {
    return (
        <span
            aria-hidden="true"
            data-testid="repo-avatar"
            className={cn(
                'inline-flex shrink-0 items-center justify-center font-semibold',
                variant === 'owner' ? 'size-5 rounded-[6px] bg-[#2f3a42] text-[11px] text-white' : cn('rounded-[5px] bg-panel-2 text-muted', size === 'sm' ? 'size-4 text-[9px]' : 'size-6 text-[11px]'),
            )}
        >
            {variant === 'owner' ? repositoryOwnerInitial(repo) : repositoryInitials(repo)}
        </span>
    );
}
