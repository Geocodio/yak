import { Badge, Skeleton, cn, toast } from '@geocodio/console-ui';
import { Copy, ExternalLink, Globe, KeyRound } from 'lucide-react';
import { useEffect, type ReactNode } from 'react';
import { InfoTip } from '@/components/threads/environment/InfoTip';
import { LocationChip } from '@/components/threads/share/LocationChip';
import { VisibilityRow } from '@/components/threads/share/VisibilityRow';
import { PersonAvatar } from '@/components/threads/environment/PersonAvatar';
import { CopyableAddress } from '@/components/CopyableAddress';
import { useDocsUrl } from '@/lib/docsUrl';
import { sshKeys } from '@/routes/settings';
import type { PanelProps } from '@/threads/panels';
import type { ThreadEnvironment } from '@/types/thread-changes';
import { CapacityBlock } from './CapacityBlock';
import { RepositoryRows } from './RepositoryRows';
import { StatusCard } from './StatusCard';
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
            return 'The sandbox is stopped. Sending a message rebuilds it from the thread history.';
        case 'destroying':
            return 'The sandbox is being removed.';
        case 'destroyed':
            return 'The sandbox was removed. Sending a message rebuilds it from the thread history.';
        case 'none':
            return 'A sandbox is created when you send the first message.';
    }
}

function Row({ label, testId, tip, children }: { label: string; testId: string; tip?: string; children: ReactNode }) {
    return (
        <div className="flex items-start gap-3 border-b border-hair py-3 last:border-b-0" data-testid={testId}>
            <dt className="flex w-24 shrink-0 items-center gap-1 text-[12px] text-muted">
                {label}
                {tip !== undefined && <InfoTip label={tip} />}
            </dt>
            <dd className="min-w-0 flex-1 text-[13px] text-body">{children}</dd>
        </div>
    );
}

const PULL_REQUEST_TONE: Record<string, 'ok' | 'accent' | 'neutral'> = { open: 'ok', merged: 'accent', closed: 'neutral' };

function capitalize(value: string): string {
    return value.charAt(0).toUpperCase() + value.slice(1);
}

function hostOf(url: string): string {
    try {
        return new URL(url).host;
    } catch {
        return url;
    }
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

export function EnvironmentPanel({ thread, canDrive, notice, composerState, capacity = null, accountName = null, environmentSwitch = null }: PanelProps) {
    const threadsDocsUrl = useDocsUrl('threads/');
    const { environment, isLoading, error, reload } = useThreadEnvironment(thread);
    const isSettingUp = environment?.repositories.some((repository) => ['pending', 'cloning', 'setup'].includes(repository.setupStatus)) ?? false;

    useEffect(() => {
        if (!isSettingUp) {
            return;
        }
        const timer = window.setInterval(reload, 3000);
        return () => window.clearInterval(timer);
    }, [isSettingUp, reload]);

    const isWaiting = notice?.kind === 'waiting_for_account' || notice?.kind === 'waiting_for_sandbox';
    const capacityBlock = isWaiting || composerState === 'working' ? <CapacityBlock capacity={capacity} accountName={accountName} /> : null;

    if (environment === null) {
        return (
            <div className="flex flex-col gap-4" data-testid="environment-panel">
                <StatusCard thread={thread} notice={notice} composerState={composerState} />
            {capacityBlock}
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

    const { sandbox, branch, pullRequest, deployment, account, startedBy, stoppedAfterMinutes, repositories, notUploadedDirectories } = environment;

    return (
        <div className="flex flex-col gap-4" data-testid="environment-panel">
            <StatusCard thread={thread} notice={notice} composerState={composerState} />
            {capacityBlock}
            <section className="rounded-card border border-hair bg-app p-3" data-testid="environment-sandbox-state">
                <div className="flex items-center gap-2 text-[13px] font-medium text-body">
                    <span className={cn('inline-block h-2 w-2 rounded-pill', DOT[sandbox.state])} />
                    {LABEL[sandbox.state]}
                </div>
                <p className="mt-1 text-[12px] text-muted">{sandboxSentence(sandbox.state, stoppedAfterMinutes)}</p>
            </section>

            {environmentSwitch?.to === 'Yak' && (
                <p className="text-[12px] text-muted" data-testid="environment-built-note">
                    Built today at {new Date(environmentSwitch.at).toLocaleTimeString(undefined, { timeStyle: 'short' })} from your computer. See the note.
                </p>
            )}

            <dl>
                <VisibilityRow thread={thread} />
                {thread.startedOnDevice && (
                    <Row label="Started on" testId="environment-started-on">
                        <LocationChip thread={thread} />
                    </Row>
                )}
                <Row label="Branch" testId="environment-branch">
                    {branch === null ? (
                        <span className="text-muted">Not created yet</span>
                    ) : (
                        <span className="flex min-w-0 items-center gap-1.5">
                            <code className="min-w-0 flex-1 truncate font-mono text-[12px]">{branch}</code>
                            <button type="button" onClick={() => copyBranch(branch)} aria-label="Copy branch name" className="shrink-0 cursor-pointer text-muted hover:text-body">
                                <Copy size={12} />
                            </button>
                        </span>
                    )}
                </Row>

                <Row label="Pull request" testId="environment-pull-request">
                    {pullRequest === null ? (
                        <span className="text-muted">Not opened yet</span>
                    ) : (
                        <span className="inline-flex items-center gap-2">
                            <ExternalAnchor href={pullRequest.url}>#{pullRequest.number}</ExternalAnchor>
                            <Badge tone={PULL_REQUEST_TONE[pullRequest.state] ?? 'neutral'}>{capitalize(pullRequest.state)}</Badge>
                        </span>
                    )}
                </Row>

                <Row label="Deployment" testId="environment-deployment" tip="The preview served from this thread's branch.">
                    {deployment === null ? (
                        <span className="text-muted">None for this branch</span>
                    ) : (
                        <span className="inline-flex items-center gap-2">
                            <ExternalAnchor href={deployment.url}>{hostOf(deployment.url)}</ExternalAnchor>
                            <Badge tone="neutral">{deployment.status}</Badge>
                        </span>
                    )}
                </Row>

                {thread.preview.host !== null && (
                    <Row label="Preview" testId="environment-preview">
                        <span className="inline-flex max-w-full items-center gap-1.5">
                            <Globe size={13} className="shrink-0 text-muted" />
                            <code className="truncate font-mono text-[12px]">{thread.preview.host}</code>
                        </span>
                        {thread.preview.state === 'ready' && (
                            <p className="mt-0.5 flex items-center gap-1.5 text-[12px] text-muted">
                                <span className="inline-block h-1.5 w-1.5 rounded-pill bg-ok" aria-hidden="true" />
                                Dev server running
                            </p>
                        )}
                    </Row>
                )}

                <Row label="Terminal" testId="environment-terminal">
                    <CopyableAddress address={`yak ssh ${thread.id}`} label={null} />
                    <p className="mt-0.5 text-[12px] text-muted">From your computer, with your GitHub keys.</p>
                    <a href={sshKeys().url} className="text-[12px] text-accent-text hover:underline" data-testid="environment-ssh-link">
                        Set up SSH
                    </a>
                </Row>

                <Row label="Account" testId="environment-account" tip="The Claude account this thread runs on.">
                    {account === null ? (
                        <span className="text-muted">Not chosen yet</span>
                    ) : (
                        <>
                            <span className="inline-flex items-center gap-2">
                                <KeyRound size={13} className="text-muted" />
                                {account.name}
                                {account.isYours && <Badge tone="accent">Yours</Badge>}
                            </span>
                            <p className="mt-0.5 text-[12px] text-muted">Setup token. Fixed for this thread.</p>
                        </>
                    )}
                </Row>

                {startedBy !== null && (
                    <Row label="Started by" testId="environment-started-by">
                        <span className="inline-flex items-center gap-2">
                            <PersonAvatar name={startedBy.name} />
                            {startedBy.name}
                            <span className="text-muted">{formatStartedAt(startedBy.at)}</span>
                        </span>
                    </Row>
                )}
            </dl>

            {repositories.length > 1 && (
                <section data-testid="environment-repositories">
                    <h3 className="mb-1 text-[12px] text-muted">Repositories</h3>
                    <RepositoryRows threadId={thread.id} repositories={repositories} canDrive={canDrive} onChanged={reload} />
                </section>
            )}

            {notUploadedDirectories.length > 0 && (
                <section data-testid="environment-not-uploaded">
                    <h3 className="mb-1 text-[12px] text-muted">Not uploaded from your computer</h3>
                    <ul className="flex flex-col gap-1">
                        {notUploadedDirectories.map((directory) => (
                            <li key={directory}>
                                <code className="font-mono text-[12px] text-body">{directory}</code>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            <a href={threadsDocsUrl} target="_blank" rel="noreferrer" className="text-[12px] text-accent-text hover:underline">
                How threads and sandboxes work
            </a>
        </div>
    );
}
