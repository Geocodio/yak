import { router } from '@inertiajs/react';
import { Button, cn } from '@geocodio/console-ui';
import { useMemo } from 'react';
import { CircleAlert, CircleCheck, ExternalLink, GitPullRequest, List, RotateCcw } from 'lucide-react';
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
import { rerunReview, retry } from '@/routes/tasks';
import type { ActionsData, ActivityRow, ActivitySummary, DeploymentData, FindingsData, ProgressStep, TaskDetail, ThreadEntryData } from '@/types/tasks';

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
    onOpenFullLog: () => void;
};

const MILESTONE_LABELS = ['Pushed', 'CI passing', 'Pull request'];

function failureTitle(mode: TaskDetail['mode']): string {
    return mode === 'setup' ? 'Setup could not finish' : mode === 'review' ? 'Review could not finish' : 'This run could not finish';
}

/** The failing step the run stopped on, drawn as a row the reader can open. */
function stoppedAt(rows: ActivityRow[]) {
    const row = [...rows].reverse().find((candidate) => candidate.error && candidate.tool !== null);

    return row ? { ...stepFromRow({ ...row, retryPassed: false, retried: false }), key: `x${row.id}` } : null;
}

function ErrorCard({ entry, context, rows }: { entry: ThreadEntryData; context: YakBubbleContext; rows: ActivityRow[] }) {
    const { task, actions } = context;
    const step = stoppedAt(rows);
    const retryAction = actions.canRerunReview ? { label: 'Re-run review', url: rerunReview.url(task.id) } : actions.canRetry ? { label: task.mode === 'setup' ? 'Retry setup' : 'Retry', url: retry.url(task.id) } : null;

    return (
        <div className="flex flex-col gap-2 rounded-[10px] bg-fail-soft p-3" data-testid="run-error">
            <h3 className="m-0 flex items-center gap-[7px] text-[14.5px] font-semibold text-fail">
                <CircleAlert size={16} className="shrink-0" aria-hidden />
                {failureTitle(task.mode)}
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
                {!live && failed && context && <ErrorCard entry={entry} context={context} rows={stream?.rows ?? []} />}
                {!live && !failed && context && focused && <Outcome entry={entry} context={context} />}
                {!live && !failed && !focused && entry.bodyHtml && <Prose html={entry.bodyHtml} className="max-w-[65ch] px-0.5 text-[14px] leading-[1.55]" />}
            </div>
        </div>
    );
}
