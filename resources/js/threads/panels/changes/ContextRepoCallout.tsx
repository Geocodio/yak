import { Button } from '@geocodio/console-ui';
import { router } from '@inertiajs/react';
import type { ThreadData } from '@/types/threads';
import { useThreadEnvironment } from '../environment/useThreadEnvironment';

type Props = {
    thread: ThreadData;
    repo: string;
    canDrive: boolean;
    onPromoted: () => void;
};

/** Explains that a context repository's edits are scratch files and offers to keep them. */
export function ContextRepoCallout({ thread, repo, canDrive, onPromoted }: Props) {
    const { environment } = useThreadEnvironment(thread);
    const repository = environment?.repositories.find((candidate) => candidate.repo === repo);

    return (
        <div className="rounded-card border border-dashed border-hair p-3 text-[12px] text-muted" data-testid="changes-context-callout">
            <p>
                {repo} is context. No pull request is opened and these files are discarded with the sandbox, not teleported. Make it a repo with changes to keep them.
            </p>
            {canDrive && repository !== undefined && (
                <Button
                    variant="secondary"
                    className="mt-2"
                    onClick={() => router.patch(`/threads/${thread.id}/repositories/${repository.id}`, { role: 'changes' }, { preserveScroll: true, preserveState: true, onFinish: onPromoted })}
                    data-testid="changes-promote"
                >
                    Make it a repo with changes
                </Button>
            )}
        </div>
    );
}
