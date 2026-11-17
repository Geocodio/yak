import { Link, router } from '@inertiajs/react';
import { Button, cn } from '@geocodio/console-ui';
import { useMemo } from 'react';
import { CircleAlert, CircleCheck, ExternalLink, GitPullRequest, List, Loader2, RotateCcw, Settings, Wrench } from 'lucide-react';
import { ActivityStream, stepFromRow } from '@/components/activity/ActivityStream';
import { Elapsed } from '@/components/activity/Elapsed';
import { FinishedSummary } from '@/components/activity/FinishedSummary';
import { OpenKeysProvider } from '@/components/activity/openKeys';
import { StepList } from '@/components/activity/StepGroup';
import { StepRow } from '@/components/activity/StepRow';
import { YakAvatar } from '@/components/brand/YakAvatar';
import { Prose } from '@/components/Prose';
import { FindingsBlock } from '@/components/tasks/FindingsBlock';
import { allFailuresRetried } from '@/components/tasks/groupActivity';
import { navigateTaskQuery } from '@/lib/taskQuery';
import { rerunReview, retry, setupAndContinue } from '@/routes/tasks';
import type { ActionsData, ActivityRow, ActivitySummary, DeploymentData, FindingsData, ProgressStep, StopData, TaskDetail, ThreadEntryData } from '@/types/tasks';

/** Where Yak's activity for the run on screen lives: the loaded rows, the run totals and the paging controls. */
export type YakStream = {
    rows: ActivityRow[];
    summary: ActivitySummary;
    hasOlder: boolean;
    loadingOlder: boolean;
    onLoadOlder: () => void;
};

export type YakBubbleContext = {
    task: TaskDetail;
    actions: ActionsData;
    deployment: DeploymentData;
    progress: ProgressStep[];
    stream: YakStream;
    findings: FindingsData;
    stop: StopData;
    onOpenFullLog: () => void;
};

const MILESTONE_LABELS = ['Pushed', 'CI passed', 'Pull request'];

function failureTitle({ mode, isFollowUp }: Pick<TaskDetail, 'mode' | 'isFollowUp'>): string {
    if (isFollowUp) {
        return 'Yak could not finish this change';
    }

    return mode === 'setup' ? 'Setup could not finish' : mode === 'review' ? 'Review could not finish' : 'This run could not finish';
}

/** The failing step the run stopped on, drawn as a row the reader can open. */
function stoppedAt(rows: ActivityRow[]) {
    const row = [...rows].reverse().find((candidate) => candidate.error && candidate.tool !== null);

    return row ? { ...stepFromRow({ ...row, retryPassed: false, retried: false }), key: `x${row.id}` } : null;
}

/** The failure card for a task whose repository has no ready sandbox: it explains why and starts setup in place. */
function RepositorySetupCard({ task, setup }: { task: TaskDetail; setup: NonNullable<TaskDetail['repositorySetup']> }) {
    const settingsButton = (
        <Button variant="secondary" icon={<Settings size={14} />} onClick={() => router.visit(setup.settingsUrl)}>
            Repository settings
        </Button>
    );
    const continueAfterSetup = () => router.post(setupAndContinue.url(task.id), {}, { preserveScroll: true });

    if (setup.isSettingUp) {
        return (
            <div className="flex flex-col gap-2 rounded-[10px] bg-warn-soft p-3" data-testid="repository-setup">
                <h3 className="m-0 flex flex-wrap items-center gap-[7px] text-[14.5px] font-semibold text-warn">
                    <Loader2 size={16} className="shrink-0 animate-spin motion-reduce:animate-none" aria-hidden />
                    Setting up {setup.repo}
                </h3>
                <p className="m-0 max-w-[65ch] text-[13.5px] leading-[1.55] text-body">
                    {setup.continuesAfterSetup
                        ? 'Yak picks this task back up when setup finishes. Setup usually takes a few minutes.'
                        : 'Setup is already running for this repository. Yak can pick this task back up when it finishes.'}
                </p>
                <div className="mt-0.5 flex flex-wrap gap-2">
                    {!setup.continuesAfterSetup && (
                        <Button variant="primary" icon={<RotateCcw size={14} />} onClick={continueAfterSetup} data-testid="setup-and-continue">
                            Continue when setup finishes
                        </Button>
                    )}
                    {settingsButton}
                </div>
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-2 rounded-[10px] bg-fail-soft p-3" data-testid="repository-setup">
            <h3 className="m-0 flex flex-wrap items-center gap-[7px] text-[14.5px] font-semibold text-fail">
                <CircleAlert size={16} className="shrink-0" aria-hidden />
                {setup.repo} needs setup
            </h3>
            <p className="m-0 max-w-[65ch] text-[13.5px] leading-[1.55] text-body">
                Yak runs every task in a sandbox that Setup builds for the repository. This repository has no ready sandbox, so Yak stopped before it could continue.
            </p>
            {setup.failedSetup && (
                <p className="m-0 text-[12.5px] text-muted">
                    The last setup failed at {setup.failedSetup.at}.{' '}
                    <Link href={setup.failedSetup.url} className="font-medium text-accent-text hover:underline">
                        View failed setup
                    </Link>
                </p>
            )}
            <div className="mt-0.5 flex flex-wrap gap-2">
                <Button variant="primary" icon={<Wrench size={14} />} onClick={continueAfterSetup} data-testid="setup-and-continue">
                    Run setup and continue
                </Button>
                {settingsButton}
            </div>
        </div>
    );
}

const STOP_STYLES = {
    yak_retrying: { label: 'Paused', block: 'bg-info-soft', tone: 'text-info', dot: 'bg-info' },
    yak_stopped: { label: 'Stopped', block: 'bg-fail-soft', tone: 'text-fail', dot: 'bg-fail' },
    needs_you: { label: 'Yak needs you', block: 'bg-warn-soft', tone: 'text-warn', dot: 'bg-warn' },
} as const;

/** What happened in plain words, what to do next, and the technical text folded under Details. */
function StopBlock({ stop, retryAction, step }: { stop: NonNullable<StopData>; retryAction: { label: string; url: string } | null; step: ReturnType<typeof stoppedAt> }) {
    const style = STOP_STYLES[stop.kind];

    return (
        <div className={cn('flex flex-col gap-2.5 rounded-[10px] px-4 py-3.5', style.block)} data-testid="task-stop" data-stop-kind={stop.kind}>
            <span className={cn('inline-flex items-center gap-2 text-[13px] font-semibold', style.tone)}>
                <span className={cn('size-2 rounded-full', style.dot)} aria-hidden />
                {style.label}
            </span>
            <p className="m-0 max-w-[65ch] text-[14.5px] leading-[1.55] text-body">{stop.summary}</p>
            <p className="m-0 max-w-[65ch] text-[13.5px] text-muted">{stop.nextStep}</p>
            {stop.isWaitingForSetup && (
                <span className="flex items-center gap-2 text-[13px] text-muted">
                    <Loader2 size={13} className="animate-spin motion-reduce:animate-none" aria-hidden />
                    Waiting for setup to finish
                </span>
            )}
            {retryAction && stop.kind !== 'yak_retrying' && (
                <div>
                    <Button variant="primary" icon={<RotateCcw size={14} />} onClick={() => router.post(retryAction.url, {}, { preserveScroll: true })} data-testid="retry-run">
                        {retryAction.label}
                    </Button>
                </div>
            )}
            {(stop.detail || step) && (
                <details className="text-[13px]">
                    <summary className="cursor-pointer text-muted">Details</summary>
                    {stop.detail && <pre className="mt-2 mb-0 overflow-x-auto rounded-lg bg-panel-2 px-3 py-2.5 font-mono text-[12.5px] whitespace-pre-wrap text-body">{stop.detail}</pre>}
                    {step && (
                        <div className="mt-2 rounded-lg bg-panel p-[3px]">
                            <OpenKeysProvider>
                                <StepList className="before:hidden">
                                    <StepRow step={step} />
                                </StepList>
                            </OpenKeysProvider>
                        </div>
                    )}
                </details>
            )}
        </div>
    );
}

function retryActionFor(task: TaskDetail, actions: ActionsData) {
    return actions.canRerunReview ? { label: 'Re-run review', url: rerunReview.url(task.id) } : actions.canRetry ? { label: task.mode === 'setup' ? 'Retry setup' : 'Retry', url: retry.url(task.id) } : null;
}

/** The stop block for a task whose final run has no bubble carrying an error (an expired clarification, for one). */
export function StandaloneStop({ task, actions, stop }: { task: TaskDetail; actions: ActionsData; stop: NonNullable<StopData> }) {
    return <StopBlock stop={stop} retryAction={retryActionFor(task, actions)} step={null} />;
}

function ErrorCard({ entry, context, rows, focused }: { entry: ThreadEntryData; context: YakBubbleContext; rows: ActivityRow[]; focused: boolean }) {
    const { task, actions } = context;

    const { stop } = context;
    // The setup card keeps its own buttons; a task already waiting on setup reads as the Paused block instead.
    if (task.repositorySetup && focused && !(stop && task.repositorySetup.continuesAfterSetup)) {
        return <RepositorySetupCard task={task} setup={task.repositorySetup} />;
    }

    const step = stoppedAt(rows);
    const retryAction = retryActionFor(task, actions);

    if (stop && focused) {
        return <StopBlock stop={stop} retryAction={retryAction} step={step} />;
    }

    return (
        <div className="flex flex-col gap-2 rounded-[10px] bg-fail-soft p-3" data-testid="run-error">
            <h3 className="m-0 flex items-center gap-[7px] text-[14.5px] font-semibold text-fail">
                <CircleAlert size={16} className="shrink-0" aria-hidden />
                {failureTitle(task)}
            </h3>
            <p className="m-0 max-w-[65ch] text-[13.5px] leading-[1.55] wrap-break-word text-body">{entry.error}</p>
            {step && (
                <div className="rounded-lg bg-panel p-[3px]">
                    <OpenKeysProvider>
                        <StepList className="before:hidden">
                            <StepRow step={step} />
                        </StepList>
                    </OpenKeysProvider>
                </div>
            )}
            {(retryAction || task.repoUrl) && (
                <div className="mt-0.5 flex flex-wrap gap-2">
                    {retryAction && (
                        <Button variant="primary" icon={<RotateCcw size={14} />} onClick={() => router.post(retryAction.url, {}, { preserveScroll: true })} data-testid="retry-run">
                            {retryAction.label}
                        </Button>
                    )}
                    {task.repoUrl && task.mode === 'setup' && (
                        <Button variant="secondary" onClick={() => router.visit(task.repoUrl as string)}>
                            Open repository settings
                        </Button>
                    )}
                </div>
            )}
        </div>
    );
}

function Outcome({ entry, context }: { entry: ThreadEntryData; context: YakBubbleContext }) {
    const { task, deployment, progress, findings } = context;
    const reached = progress.filter((step) => MILESTONE_LABELS.includes(step.label) && step.done);
    const chipText = (label: string) => (label === 'Pushed' && task.branch ? `Pushed ${task.branch}` : label === 'Pull request' && task.pr?.number ? `Pull request #${task.pr.number}` : label);
    const researchLink = entry.links?.find((link) => link.label === 'View research artifact');
    const pullRequestLink = entry.links?.find((link) => link.label !== 'View research artifact');
    const reviewLabel = task.pr?.number ? `Review PR #${task.pr.number}` : 'Review pull request';

    return (
        <>
            {entry.bodyHtml && <Prose html={entry.bodyHtml} className="max-w-[65ch] px-0.5 pt-1 text-[14.5px] leading-[1.6] prose-headings:mt-0 prose-headings:mb-1.5 prose-headings:text-[17px] prose-headings:leading-[1.3] prose-headings:font-semibold prose-ul:my-2.5 prose-ul:gap-1" />}
            {findings && <FindingsBlock findings={findings} />}
            {reached.length > 0 && (
                <div className="flex flex-wrap gap-1.5" data-testid="run-milestones">
                    {reached.map((step) => (
                        <span key={step.label} className="inline-flex items-center gap-[5px] rounded-full bg-panel-2 py-[3px] pr-[9px] pl-1.5 text-[12px] text-muted">
                            <CircleCheck size={13} className="text-ok" aria-hidden />
                            {chipText(step.label)}
                        </span>
                    ))}
                </div>
            )}
            {(pullRequestLink || deployment || researchLink) && (
                <div className="mt-1.5 flex flex-wrap gap-2">
                    {pullRequestLink && (
                        <a href={pullRequestLink.url} target="_blank" rel="noopener noreferrer" data-testid="pr-link" className="no-underline">
                            <Button variant="primary" icon={<GitPullRequest size={14} />} tabIndex={-1}>
                                {reviewLabel}
                            </Button>
                        </a>
                    )}
                    {deployment && (
                        <Button variant="secondary" icon={<ExternalLink size={14} />} onClick={() => window.open(deployment.url, '_blank', 'noopener,noreferrer')} data-testid="open-preview">
                            Open preview
                        </Button>
                    )}
                    {researchLink && (
                        <a href={researchLink.url} data-testid="research-link" className="inline-flex items-center rounded-control border border-hair bg-panel px-3 py-1.5 text-[13px] font-medium text-accent-text hover:bg-panel-2">
                            {researchLink.label}
                        </a>
                    )}
                </div>
            )}
        </>
    );
}

/**
 * Yak's reply for one run. While it works the bubble is the live stream of
 * steps; when it finishes the steps fold into "Worked for ..." and the answer
 * leads, with milestone chips and the actions beside it. A run that failed
 * shows its error once, here, where it stopped.
 */
export function YakBubble({ entry, context, focused }: { entry: ThreadEntryData; context: YakBubbleContext | null; focused: boolean }) {
    const stream = focused && context ? context.stream : null;
    const live = entry.live === true;
    const failed = entry.error !== null && entry.error !== undefined;
    const hasActivity = stream !== null && stream.summary.entries > 0;
    const retried = useMemo(() => stream !== null && allFailuresRetried(stream.rows), [stream]);
    const earlierCount = stream ? Math.max(0, stream.summary.entries - stream.rows.length) : 0;

    return (
        <div className={cn('rounded-xl border border-hair bg-panel px-3 pt-3 pb-3.5 shadow-card', entry.superseded && 'opacity-60')} data-testid="yak-bubble" data-run-id={entry.runId ?? undefined}>
            <div className="mb-2 flex min-w-0 items-center gap-2 text-[12.5px]">
                <YakAvatar />
                <b className="font-semibold">Yak</b>
                <span className={cn('tnum inline-flex min-w-0 items-center gap-1.5 whitespace-nowrap', live ? 'text-accent-text' : 'text-faint')}>
                    {live && <span className="activity-pulse size-1.5 flex-none rounded-full bg-current" />}
                    {live && stream ? (
                        <>
                            step {stream.summary.entries.toLocaleString()} · <Elapsed since={entry.startedAt ?? null} />
                        </>
                    ) : (
                        entry.meta
                    )}
                </span>
                {stream && context && hasActivity && (
                    <button
                        type="button"
                        onClick={context.onOpenFullLog}
                        data-testid="open-transcript"
                        className="ml-auto inline-flex cursor-pointer items-center gap-[5px] rounded-md px-[7px] py-[3px] text-[12px] font-medium whitespace-nowrap text-muted hover:bg-panel-2 hover:text-body"
                    >
                        <List size={13} aria-hidden />
                        Full log
                    </button>
                )}
                {!focused && entry.runId != null && (
                    <button
                        type="button"
                        onClick={() => navigateTaskQuery({ run: entry.runId, attempt: undefined })}
                        data-testid={`show-run-${entry.runId}`}
                        className="ml-auto cursor-pointer rounded-md px-[7px] py-[3px] text-[12px] font-medium whitespace-nowrap text-accent-text hover:bg-panel-2"
                    >
                        Show activity
                    </button>
                )}
            </div>

            <div className="flex flex-col gap-2">
                {stream && live && (
                    <ActivityStream
                        rows={stream.rows}
                        live
                        startedAt={entry.startedAt ?? null}
                        earlierCount={earlierCount}
                        hasOlder={stream.hasOlder}
                        loadingOlder={stream.loadingOlder}
                        onLoadOlder={stream.onLoadOlder}
                    />
                )}
                {stream && !live && hasActivity && (
                    <FinishedSummary durationMs={entry.durationMs ?? null} counts={stream.summary.counts} failed={stream.summary.failed} retried={retried} stopped={failed}>
                        <ActivityStream
                            rows={stream.rows}
                            live={false}
                            startedAt={entry.startedAt ?? null}
                            earlierCount={earlierCount}
                            hasOlder={stream.hasOlder}
                            loadingOlder={stream.loadingOlder}
                            onLoadOlder={stream.onLoadOlder}
                        />
                    </FinishedSummary>
                )}
                {!live && failed && context && <ErrorCard entry={entry} context={context} rows={stream?.rows ?? []} focused={Boolean(focused)} />}
                {!live && !failed && context && focused && <Outcome entry={entry} context={context} />}
                {!live && !failed && !focused && entry.bodyHtml && <Prose html={entry.bodyHtml} className="max-w-[65ch] px-0.5 text-[14px] leading-[1.55]" />}
            </div>
        </div>
    );
}
