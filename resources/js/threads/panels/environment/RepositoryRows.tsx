import { Badge, Button, cn } from '@geocodio/console-ui';
import { router } from '@inertiajs/react';
import type { ThreadEnvironmentRepository } from '@/types/thread-changes';

const ROLE_LABEL: Record<ThreadEnvironmentRepository['role'], string> = { primary: 'Primary', changes: 'Changes', context: 'Context' };

const STATUS_DOT: Record<ThreadEnvironmentRepository['setupStatus'], string> = {
    pending: 'bg-idle',
    cloning: 'bg-info',
    setup: 'bg-info',
    ready: 'bg-ok',
    failed: 'bg-fail',
};

const STATUS_LABEL: Record<ThreadEnvironmentRepository['setupStatus'], string> = {
    pending: 'Queued',
    cloning: 'Cloning',
    setup: 'Running setup',
    ready: 'Ready',
    failed: 'Setup failed',
};

function roleSentence(repository: ThreadEnvironmentRepository): string {
    if (repository.role === 'context') {
        return 'Read only. No pull request, discarded with the sandbox.';
    }
    return 'Gets a pull request if it changes';
}

export function RepositoryRows({ threadId, repositories, canDrive, onChanged }: { threadId: number; repositories: ThreadEnvironmentRepository[]; canDrive: boolean; onChanged: () => void }) {
    const send = (method: 'patch' | 'post', url: string, data: Record<string, string>) =>
        router[method](url, data, { preserveScroll: true, preserveState: true, onFinish: onChanged });

    return (
        <ul className="flex flex-col gap-2" data-testid="environment-repository-list">
            {repositories.map((repository) => (
                <li key={repository.id} className="rounded-card border border-hair p-3 text-[13px]" data-testid={`environment-repository-${repository.repo}`}>
                    <div className="flex items-center justify-between gap-2">
                        <span className="truncate font-medium text-body">{repository.repo}</span>
                        <Badge tone={repository.role === 'primary' ? 'accent' : 'neutral'}>{ROLE_LABEL[repository.role]}</Badge>
                    </div>
                    <code className="font-mono text-[12px] text-muted">{repository.path}</code>
                    <p className="mt-1 flex items-center gap-2 text-[12px] text-muted">
                        <span className={cn('inline-block h-2 w-2 rounded-pill', STATUS_DOT[repository.setupStatus])} />
                        {STATUS_LABEL[repository.setupStatus]}
                    </p>
                    <p className="mt-0.5 text-[12px] text-muted">{roleSentence(repository)}</p>
                    {!repository.isDatasetAttached && repository.role !== 'primary' && <p className="mt-0.5 text-[12px] text-muted">dataset not attached</p>}

                    {repository.setupStatus === 'failed' && (
                        <div className="mt-2" data-testid="environment-repository-error">
                            <p className="text-[12px] text-fail">{repository.setupError ?? 'Setup failed.'}</p>
                            {canDrive && (
                                <div className="mt-2 flex flex-wrap items-center gap-2">
                                    <Button variant="secondary" onClick={() => send('post', `/threads/${threadId}/repositories/${repository.id}/retry`, {})} data-testid="environment-repository-retry">
                                        Retry
                                    </Button>
                                    {repository.canMakeContextOnly ? (
                                        <Button variant="tertiary" onClick={() => send('patch', `/threads/${threadId}/repositories/${repository.id}`, { role: 'context' })} data-testid="environment-repository-context">
                                            Make it context only
                                        </Button>
                                    ) : (
                                        repository.role === 'changes' && <span className="text-[12px] text-muted">Close its pull request to make it context only.</span>
                                    )}
                                </div>
                            )}
                        </div>
                    )}
                </li>
            ))}
        </ul>
    );
}
