import { Button } from '@geocodio/console-ui';
import { router } from '@inertiajs/react';
import { Info } from 'lucide-react';
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
        <div className="rounded-card bg-warn-soft p-3 text-[12px] text-body" data-testid="changes-context-callout">
            <p className="flex items-start gap-2">
                <Info size={14} className="mt-0.5 shrink-0 text-warn" aria-hidden="true" />
                <span>
                    <strong className="font-semibold">{repo} is context.</strong> No pull request is opened and these files are discarded with the sandbox, not teleported. Make it a repo with changes to keep them.
                </span>
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
