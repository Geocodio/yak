import { Badge, Skeleton, cn, toast } from '@geocodio/console-ui';
import { Copy, ExternalLink } from 'lucide-react';
import type { ReactNode } from 'react';
import type { PanelProps } from '@/threads/panels';
import type { ThreadEnvironment } from '@/types/thread-changes';
import { useThreadEnvironment } from './useThreadEnvironment';

type SandboxState = ThreadEnvironment['sandbox']['state'];

const DOT: Record<SandboxState, string> = {
    running: 'bg-ok',
    starting: 'bg-info',
    paused: 'bg-warn',
    stopped: 'bg-idle',
    destroying: 'bg-idle',
    destroyed: 'bg-idle',
    none: 'bg-idle',
};

const LABEL: Record<SandboxState, string> = {
    running: 'Running',
    starting: 'Starting',
    paused: 'Paused',
    stopped: 'Stopped',
    destroying: 'Being removed',
    destroyed: 'Removed',
    none: 'No sandbox yet',
};

function sandboxSentence(state: SandboxState, stoppedAfterMinutes: number): string {
    switch (state) {
        case 'running':
            return 'The sandbox is awake and ready.';
        case 'starting':
            return 'The sandbox is starting.';
        case 'paused':
            return `Wakes in about a second when you send a message. Stops on its own after ${stoppedAfterMinutes} idle minutes.`;
        case 'stopped':
            return 'The sandbox is stopped. Sending a message restores it from the thread history.';
        case 'destroying':
            return 'The sandbox is being removed.';
        case 'destroyed':
            return 'The sandbox was removed. Sending a message restores it from the thread history.';
        case 'none':
            return 'A sandbox is created when you send the first message.';
    }
}

function Row({ label, testId, children }: { label: string; testId: string; children: ReactNode }) {
    return (
        <div className="flex flex-col gap-1 border-b border-hair py-3 last:border-b-0" data-testid={testId}>
            <dt className="text-[12px] text-muted">{label}</dt>
            <dd className="min-w-0 text-[13px] text-body">{children}</dd>
        </div>
    );
}

function ExternalAnchor({ href, children }: { href: string; children: ReactNode }) {
    return (
        <a href={href} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-accent-text hover:underline">
            {children}
            <ExternalLink size={12} />
        </a>
    );
}

function copyBranch(branch: string): void {
    navigator.clipboard.writeText(branch).then(
        () => toast.success('Copied the branch name'),
        () => toast.error('Could not copy the branch name.'),
    );
}

function formatStartedAt(iso: string): string {
    return new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

export function EnvironmentPanel({ thread }: PanelProps) {
    const { environment, isLoading, error } = useThreadEnvironment(thread);

    if (environment === null) {
        return (
            <div data-testid="environment-panel">
                {isLoading ? (
                    <div className="flex flex-col gap-3" data-testid="environment-loading">
                        <Skeleton className="h-20 w-full" />
                        <Skeleton className="h-4 w-2/3" />
                        <Skeleton className="h-4 w-1/2" />
                    </div>
                ) : (
                    <p className="text-[13px] text-muted" data-testid="environment-error">
                        {error ?? 'The environment could not be loaded.'}
                    </p>
                )}
            </div>
        );
    }

    const { sandbox, branch, pullRequest, deployment, account, model, startedBy, stoppedAfterMinutes, repositories } = environment;

    return (
        <div className="flex flex-col gap-4" data-testid="environment-panel">
            <section className="rounded-card border border-hair bg-app p-3" data-testid="environment-sandbox-state">
                <div className="flex items-center gap-2 text-[13px] font-medium text-body">
                    <span className={cn('inline-block h-2 w-2 rounded-pill', DOT[sandbox.state])} />
                    {LABEL[sandbox.state]}
                </div>
                <p className="mt-1 text-[12px] text-muted">{sandboxSentence(sandbox.state, stoppedAfterMinutes)}</p>
            </section>

            <dl>
                <Row label="Branch" testId="environment-branch">
                    {branch === null ? (
                        <span className="text-muted">Not created yet</span>
                    ) : (
                        <span className="inline-flex max-w-full items-center gap-1.5">
                            <code className="truncate font-mono text-[12px]">{branch}</code>
                            <button type="button" onClick={() => copyBranch(branch)} aria-label="Copy branch name" className="cursor-pointer text-muted hover:text-body">
                                <Copy size={12} />
                            </button>
                        </span>
                    )}
                </Row>

                <Row label="Pull request" testId="environment-pull-request">
                    {pullRequest === null ? (
                        <span className="text-muted">None yet</span>
                    ) : (
                        <span className="inline-flex items-center gap-2">
                            <ExternalAnchor href={pullRequest.url}>#{pullRequest.number}</ExternalAnchor>
                            <Badge tone="neutral">{pullRequest.state}</Badge>
                        </span>
                    )}
                </Row>

                <Row label="Deployment" testId="environment-deployment">
                    {deployment === null ? (
                        <span className="text-muted">No preview</span>
                    ) : (
                        <span className="inline-flex items-center gap-2">
                            <ExternalAnchor href={deployment.url}>Open preview</ExternalAnchor>
                            <Badge tone="neutral">{deployment.status}</Badge>
                        </span>
                    )}
                </Row>

                <Row label="Account" testId="environment-account">
                    {account === null ? (
                        <span className="text-muted">Not chosen yet</span>
                    ) : (
                        <>
                            <span className="inline-flex items-center gap-2">
                                {account.name}
                                {account.isYours && <Badge tone="accent">Yours</Badge>}
                            </span>
                            <p className="mt-0.5 text-[12px] text-muted">Setup token. Fixed for this thread.</p>
                        </>
                    )}
                </Row>

                <Row label="Model" testId="environment-model">
                    {model === null ? <span className="text-muted">Default</span> : model}
                    <p className="mt-0.5 text-[12px] text-muted">Change it next to Send.</p>
                </Row>

                {startedBy !== null && (
                    <Row label="Started by" testId="environment-started-by">
                        {startedBy.name}, {formatStartedAt(startedBy.at)}
                    </Row>
                )}
            </dl>

            {repositories.length > 1 && (
                <section data-testid="environment-repositories">
                    <h3 className="mb-1 text-[12px] text-muted">Repositories</h3>
                    <ul className="flex flex-col gap-1 text-[13px] text-body">
                        {repositories.map((repository) => (
                            <li key={repository.repo} className="flex items-center justify-between gap-2">
                                <span className="truncate">{repository.repo}</span>
                                <Badge tone="neutral">{repository.role}</Badge>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            <a href="https://yak.build/docs/threads" target="_blank" rel="noreferrer" className="text-[12px] text-accent-text hover:underline">
                How threads and sandboxes work
            </a>
        </div>
    );
}
