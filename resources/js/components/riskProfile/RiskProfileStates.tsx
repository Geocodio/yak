import { useForm } from '@inertiajs/react';
import { Button, Skeleton } from '@geocodio/console-ui';
import { CircleAlert, ExternalLink, GitPullRequest, Loader2, RefreshCw, ShieldCheck, Sparkles } from 'lucide-react';
import type { ReactNode } from 'react';
import { formatAgo } from '@/lib/format';
import type { RiskProfileFile, RiskProfileGeneration, RiskProfileRepository } from '@/types/riskProfile';

const WHAT_FOR =
    'A risk profile lists the parts of this repository that carry more risk than the rest, such as billing, authentication and migrations, each with a risk level and an owner. Yak reads it to decide which of its own pull requests need a co-owner before they open. Until one exists, Yak has nothing to rate changes against.';

const SURFACE = { neutral: 'bg-panel-2', ok: 'bg-ok-soft', fail: 'bg-fail-soft', warn: 'bg-warn-soft' } as const;

function Card({ testId, icon, title, children }: { testId: string; icon: ReactNode; title: string; children: ReactNode }) {
    return (
        <div data-testid={testId} className="mx-auto flex max-w-[640px] flex-col items-center gap-3 rounded-card border border-hair bg-panel px-6 py-10 text-center">
            <div className="flex size-10 items-center justify-center rounded-full bg-panel-2">{icon}</div>
            <h2 className="text-[16px] font-semibold">{title}</h2>
            {children}
        </div>
    );
}

export function pluralize(count: number, noun: string): string {
    return `${count} ${noun}${count === 1 ? '' : 's'}`;
}

export function Callout({ tone, icon, children }: { tone: keyof typeof SURFACE; icon: ReactNode; children: ReactNode }) {
    return (
        <div role="status" className={`flex w-full items-start gap-3 rounded-card border border-hair p-4 text-left ${SURFACE[tone]}`}>
            <div className="mt-0.5 shrink-0">{icon}</div>
            <div className="min-w-0 flex-1 space-y-1.5 text-[13px]">{children}</div>
        </div>
    );
}

function ExternalLinkButton({ href, icon, children, primary }: { href: string; icon: ReactNode; children: ReactNode; primary?: boolean }) {
    return (
        <a href={href} target="_blank" rel="noopener noreferrer">
            <Button variant={primary ? 'primary' : undefined} icon={icon}>
                {children}
            </Button>
        </a>
    );
}

function GenerateButton({ repository, label, icon }: { repository: RiskProfileRepository; label: string; icon: ReactNode }) {
    const form = useForm({ action: 'generate' });

    return (
        <Button data-testid="risk-profile-generate" variant="primary" icon={icon} pending={form.processing} onClick={() => form.post(repository.generateUrl)}>
            {label}
        </Button>
    );
}

function ErrorBlock({ lines }: { lines: string[] }) {
    return (
        <pre data-testid="risk-profile-error" className="whitespace-pre-wrap break-words rounded-control bg-panel px-3 py-2 font-mono text-[12px] text-fail">
            {lines.join('\n')}
        </pre>
    );
}

function minutesBetween(start: string | null, end: string | null): string | null {
    if (!start || !end) {
        return null;
    }

    const minutes = Math.max(1, Math.round((new Date(end).getTime() - new Date(start).getTime()) / 60_000));

    return `${minutes} ${minutes === 1 ? 'minute' : 'minutes'}`;
}

export function LoadingState({ branch }: { branch: string }) {
    return (
        <div data-testid="risk-profile-loading" role="status" aria-busy="true" className="space-y-4">
            <div className="flex items-start gap-3 rounded-card border border-hair bg-panel-2 p-4">
                <RefreshCw size={16} className="mt-0.5 shrink-0 text-muted" />
                <div className="flex-1 space-y-2">
                    <div className="text-[13px] font-medium">Reading the file tree and risk profile from GitHub…</div>
                    <div className="font-mono text-[12px] text-muted">.yak/risk-profile.yml on {branch}</div>
                    <Skeleton className="h-3 w-1/2" />
                </div>
            </div>
            <div className="grid grid-cols-2 gap-3 md:grid-cols-5">
                {[0, 1, 2, 3, 4].map((tile) => (
                    <Skeleton key={tile} className="h-[72px] w-full" />
                ))}
            </div>
            <Skeleton className="h-[360px] w-full" />
        </div>
    );
}

export function EmptyState({ repository }: { repository: RiskProfileRepository }) {
    return (
        <Card testId="risk-profile-empty" icon={<ShieldCheck size={18} className="text-muted" />} title="No risk profile yet">
            <p className="text-[13px] text-muted">{WHAT_FOR}</p>
            <GenerateButton repository={repository} label="Generate risk profile" icon={<GitPullRequest size={13} />} />
            <p className="text-[12px] text-muted">
                Opens a PR with <span className="font-mono">.yak/risk-profile.yml</span> for review. Takes about ten minutes.
            </p>
            <ol className="mt-2 grid w-full gap-3 text-left text-[12px] text-muted md:grid-cols-3">
                <li>
                    <b className="block text-text">1. Yak reads the repository</b>
                    It looks at the file tree, ownership files and recent history, then drafts the areas.
                </li>
                <li>
                    <b className="block text-text">2. It opens a PR</b>
                    The PR adds <span className="font-mono">.yak/risk-profile.yml</span>. Review it and edit any area on the branch.
                </li>
                <li>
                    <b className="block text-text">3. You merge it</b>
                    Merging is the approval. Nothing takes effect before that.
                </li>
            </ol>
        </Card>
    );
}

export function GeneratingState({ generation }: { generation: RiskProfileGeneration }) {
    const task = generation.task;

    return (
        <Card testId="risk-profile-generating" icon={<Loader2 size={18} className="animate-spin text-muted" />} title="Generating the risk profile">
            <p className="text-[13px] text-muted">Yak is drafting the profile now. When it finishes, it opens a PR for you to review.</p>
            {task && (
                <>
                    <Callout tone="neutral" icon={<Loader2 size={16} className="animate-spin text-muted" />}>
                        <b>Task running</b>
                        <p className="text-muted">
                            {task.startedAt && <>Started {formatAgo(task.startedAt)}. </>}Task #{task.id}
                        </p>
                    </Callout>
                    <ExternalLinkButton href={task.url} icon={<ExternalLink size={13} />}>
                        View task
                    </ExternalLinkButton>
                </>
            )}
        </Card>
    );
}

export function WaitingState({ generation }: { generation: RiskProfileGeneration }) {
    const pullRequest = generation.pullRequest;

    if (!pullRequest) {
        return null;
    }

    const details = [
        pullRequest.areas === null ? null : pluralize(pullRequest.areas, 'area'),
        pullRequest.unknowns === null ? null : pluralize(pullRequest.unknowns, 'open question'),
    ].filter((part): part is string => part !== null);

    return (
        <Card testId="risk-profile-waiting" icon={<GitPullRequest size={18} className="text-ok" />} title="The profile PR is waiting for merge">
            <p className="text-[13px] text-muted">
                Yak opened a PR that adds <span className="font-mono">.yak/risk-profile.yml</span>. Review the areas, edit them on the branch if needed, then merge. Yak starts using
                the profile once it is on the default branch.
            </p>
            <Callout tone="neutral" icon={<GitPullRequest size={16} className="text-muted" />}>
                <b>
                    <a href={pullRequest.url} target="_blank" rel="noopener noreferrer" className="text-accent-text hover:underline">
                        #{pullRequest.number}
                    </a>{' '}
                    {pullRequest.title}
                </b>
                {details.length > 0 && <p className="text-muted">{details.join(' · ')}</p>}
            </Callout>
            <ExternalLinkButton primary href={pullRequest.url} icon={<ExternalLink size={13} />}>
                Open PR on GitHub
            </ExternalLinkButton>
        </Card>
    );
}

export function FailedState({ generation, repository }: { generation: RiskProfileGeneration; repository: RiskProfileRepository }) {
    const task = generation.task;
    const duration = task ? minutesBetween(task.startedAt, task.completedAt) : null;

    return (
        <Card testId="risk-profile-failed" icon={<CircleAlert size={18} className="text-fail" />} title="Generating the risk profile failed">
            <p className="text-[13px] text-muted">The task stopped before it opened a PR, so nothing was written to the repository.</p>
            {task && (
                <Callout tone="fail" icon={<CircleAlert size={16} className="text-fail" />}>
                    <b>
                        Task #{task.id} failed{duration ? ` after ${duration}` : ''}
                    </b>
                    {task.error && <ErrorBlock lines={[task.error]} />}
                </Callout>
            )}
            <div className="flex flex-wrap justify-center gap-2">
                <GenerateButton repository={repository} label="Retry generation" icon={<RefreshCw size={13} />} />
                {task && (
                    <ExternalLinkButton href={task.url} icon={<ExternalLink size={13} />}>
                        View task log
                    </ExternalLinkButton>
                )}
            </div>
            <p className="text-[12px] text-muted">A retry starts a new task and, when it succeeds, opens a new PR.</p>
        </Card>
    );
}

export function GenerationState({ generation, repository }: { generation: RiskProfileGeneration | null; repository: RiskProfileRepository }) {
    if (generation?.status === 'running') {
        return <GeneratingState generation={generation} />;
    }

    if (generation?.status === 'waiting') {
        return <WaitingState generation={generation} />;
    }

    if (generation?.status === 'failed') {
        return <FailedState generation={generation} repository={repository} />;
    }

    return null;
}

export function NeverValidState({ file, generation, repository }: { file: RiskProfileFile | null; generation: RiskProfileGeneration | null; repository: RiskProfileRepository }) {
    const lines = (file?.error ?? '').split('\n').filter((line) => line !== '');

    return (
        <div data-testid="risk-profile-never-valid" className="space-y-4">
            <GenerationState generation={generation} repository={repository} />
            <Callout tone="fail" icon={<CircleAlert size={16} className="text-fail" />}>
                <b>
                    <span className="font-mono">.yak/risk-profile.yml</span> has never been valid
                </b>
                <p className="text-muted">
                    No commit of this file passed validation, so Yak has no profile to read.
                    {file?.errorCommitSha && (
                        <>
                            {' '}
                            It was added in{' '}
                            {file.errorCommitUrl ? (
                                <a href={file.errorCommitUrl} target="_blank" rel="noopener noreferrer" className="font-mono text-accent-text hover:underline">
                                    {file.errorCommitSha.slice(0, 7)}
                                </a>
                            ) : (
                                <span className="font-mono">{file.errorCommitSha.slice(0, 7)}</span>
                            )}
                            {file.errorPullRequest && (
                                <>
                                    {' '}
                                    by{' '}
                                    <a href={file.errorPullRequest.url} target="_blank" rel="noopener noreferrer" className="text-accent-text hover:underline">
                                        #{file.errorPullRequest.number}
                                    </a>
                                </>
                            )}
                            .
                        </>
                    )}
                </p>
                {lines.length > 0 && <ErrorBlock lines={lines} />}
            </Callout>
            <Card testId="risk-profile-never-valid-card" icon={<ShieldCheck size={18} className="text-muted" />} title="No usable risk profile">
                <p className="text-[13px] text-muted">{WHAT_FOR}</p>
                <div className="flex flex-wrap justify-center gap-2">
                    {file?.blobUrl && (
                        <ExternalLinkButton primary href={file.blobUrl} icon={<ExternalLink size={13} />}>
                            Open the file on GitHub
                        </ExternalLinkButton>
                    )}
                    <GenerateButton repository={repository} label="Generate a replacement" icon={<Sparkles size={13} />} />
                </div>
                <p className="text-[12px] text-muted">
                    A replacement opens a PR that overwrites <span className="font-mono">.yak/risk-profile.yml</span> for review.
                </p>
            </Card>
        </div>
    );
}

export function UnavailableState() {
    return (
        <div data-testid="risk-profile-unavailable" className="rounded-card border border-hair bg-panel-2 p-6 text-[13px] text-muted">
            Yak reads the risk profile through its GitHub App, and this installation has none configured.
        </div>
    );
}
