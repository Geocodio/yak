import { Head, Link, useForm, usePoll } from '@inertiajs/react';
import { Badge, Button, PageHeader, cn } from '@geocodio/console-ui';
import { ExternalLink } from 'lucide-react';
import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { useCommandPaletteActions } from '@/commandPalette/useCommandPaletteActions';
import type { PaletteAction } from '@/commandPalette/types';
import { AppLayout } from '@/layouts/AppLayout';
import { Prose } from '@/components/Prose';
import { FindingsBlock } from '@/components/shared/FindingsBlock';
import { RunLogOverlay, type RunLogTarget } from '@/components/threads/conversation/RunLogOverlay';
import { rerun } from '@/routes/pr-reviews/for-pr';
import { rerun as rerunRun } from '@/routes/pr-reviews/for-pr/runs';
import { show as showThread } from '@/routes/threads';
import type { PageProps } from '@/types/shared';
import type { PrReviewForPrSummary, PrReviewPullRequest, PrReviewRun, PrReviewRunStatus } from '@/types/prReviews';

type Props = PageProps<{
    pr: PrReviewForPrSummary;
    runs: PrReviewRun[];
    pullRequest: PrReviewPullRequest;
}>;

const STATUS: Record<PrReviewRunStatus, { label: string; tone: 'ok' | 'fail' | 'info' | 'neutral' }> = {
    queued: { label: 'Queued', tone: 'neutral' },
    reviewing: { label: 'Reviewing', tone: 'info' },
    reviewed: { label: 'Reviewed', tone: 'ok' },
    failed: { label: 'Failed', tone: 'fail' },
    cancelled: { label: 'Cancelled', tone: 'neutral' },
};

function formatTime(iso: string): string {
    const date = new Date(iso);
    const time = date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });

    return date.toDateString() === new Date().toDateString() ? time : `${date.toLocaleDateString([], { month: 'short', day: 'numeric' })} ${time}`;
}

const formatDuration = (seconds: number) => (seconds < 60 ? `${seconds}s` : `${Math.round(seconds / 60)}m`);
const formatCost = (usd: number) => `$${usd.toFixed(2)}`;

function RunMeta({ run }: { run: PrReviewRun }) {
    const parts = [
        formatTime(run.startedAt),
        run.commit !== null ? <span key="c">commit <span className="font-mono">{run.commit}</span></span> : null,
        run.model,
        run.stepCount > 0 ? `${run.stepCount} steps` : null,
        run.durationSeconds > 0 ? formatDuration(run.durationSeconds) : null,
        run.costUsd > 0 ? formatCost(run.costUsd) : null,
    ].filter((part) => part !== null);

    return (
        <span className="min-w-0 text-[12px] text-muted">
            {parts.map((part, index) => (
                <span key={index}>
                    {index > 0 && ' · '}
                    {part}
                </span>
            ))}
        </span>
    );
}

function RunCard({ run, pr, isExpanded, onShow, onOpenLog }: { run: PrReviewRun; pr: PrReviewForPrSummary; isExpanded: boolean; onShow: () => void; onOpenLog: () => void }) {
    const form = useForm({});
    const status = STATUS[run.status];
    const title = run.scope === 'incremental' ? 'Incremental review' : 'Full review';
    const findingCount = run.counts.mustFix + run.counts.shouldFix + run.counts.consider;
    const isFailed = run.status === 'failed';

    return (
        <div id={`run-${run.id}`} className={cn('scroll-mt-4 rounded-card border border-hair p-4 shadow-card', isFailed ? 'bg-fail-soft' : 'bg-panel')} data-testid={`review-run-${run.id}`} data-status={run.status}>
            <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
                <Badge tone={status.tone}>{status.label}</Badge>
                <b className="text-[13px] text-body">{title}</b>
                <RunMeta run={run} />
                <span className="flex-1" />
                {!isExpanded && findingCount > 0 && (
                    <span className="text-[12px] text-muted">
                        {findingCount} {findingCount === 1 ? 'finding' : 'findings'}, {run.resolvedCount} resolved
                    </span>
                )}
                {isExpanded ? (
                    <Button variant="secondary" onClick={onOpenLog} data-testid="run-full-log">
                        Full log
                    </Button>
                ) : (
                    <Button variant="secondary" onClick={onShow} data-testid="run-show">
                        Show
                    </Button>
                )}
            </div>
            {isExpanded && (
                <div className="mt-3 space-y-3">
                    {run.isLive && (
                        <p className="text-[13px] text-muted" data-testid="run-live">
                            {run.latestActivity ?? (run.status === 'queued' ? 'Waiting for a free sandbox.' : 'Starting the review.')}
                        </p>
                    )}
                    {run.error !== null && (
                        <div data-testid="run-error">
                            <p className="text-[13px] font-medium text-body">Review could not finish.</p>
                            <p className="mt-1 whitespace-pre-wrap break-words text-[13px] text-muted">{run.error}</p>
                        </div>
                    )}
                    {run.findings === null && run.verdict !== null && <Prose html={run.verdict} className="text-[13px]" />}
                    {run.findings !== null && (
                        <div className="-mt-3 overflow-x-auto">
                            <FindingsBlock findings={run.findings} />
                        </div>
                    )}
                    {run.canRerun && (isFailed || run.status === 'cancelled') && (
                        <div>
                            <Button
                                variant="secondary"
                                pending={form.processing}
                                onClick={() => form.post(rerunRun.url({ repoSlug: pr.repoSlug, prNumber: pr.number, task: run.id }), { preserveScroll: true })}
                                data-testid="run-rerun"
                            >
                                Re-run this review
                            </Button>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

function Row({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex items-baseline justify-between gap-3 text-[12.5px]">
            <span className="text-muted">{label}</span>
            <span className="min-w-0 truncate text-right text-body">{children}</span>
        </div>
    );
}

function PanelGroup({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="space-y-2">
            <h3 className="text-[11px] font-medium uppercase tracking-wide text-faint">{title}</h3>
            {children}
        </section>
    );
}

function SidePanel({ panel }: { panel: PrReviewPullRequest }) {
    const stateTone = panel.state === 'open' ? 'ok' : panel.state === 'merged' ? 'accent' : 'neutral';

    return (
        <aside className="space-y-5 rounded-card border border-hair bg-panel p-4 shadow-card lg:self-start" data-testid="review-side-panel">
            <PanelGroup title="Details">
                <Row label="Repository">{panel.repository}</Row>
                {panel.author !== null && <Row label="Author">{panel.author}</Row>}
                <Row label="State">
                    <Badge tone={stateTone}>{panel.state}</Badge>
                </Row>
                {panel.branch !== null && <Row label="Branch"><span className="font-mono">{panel.branch}</span></Row>}
                {panel.preview !== null && (
                    <Row label="Preview">
                        <Badge tone={panel.preview.status === 'running' ? 'ok' : 'info'}>{panel.preview.status}</Badge>
                    </Row>
                )}
            </PanelGroup>
            <PanelGroup title="Reviews">
                <Row label="Runs">
                    {panel.runCount}
                    {panel.failedCount > 0 ? ` (${panel.failedCount} failed)` : ''}
                </Row>
                <Row label="Total cost">{formatCost(panel.totalCostUsd)}</Row>
                {panel.helpfulCount + panel.notHelpfulCount > 0 && (
                    <Row label="Reactions">
                        {panel.helpfulCount} helpful, {panel.notHelpfulCount} not
                    </Row>
                )}
            </PanelGroup>
            {(panel.thread !== null || panel.source !== null) && (
                <PanelGroup title="Related">
                    {panel.thread !== null && (
                        <Row label="Thread">
                            <Link href={showThread.url(panel.thread.id)} className="text-accent-text hover:underline">
                                {panel.thread.title}
                            </Link>
                        </Row>
                    )}
                    {panel.source !== null && (
                        <Row label="Issue">
                            <a href={panel.source.url} target="_blank" rel="noopener" className="text-accent-text hover:underline">
                                {panel.source.label}
                            </a>
                        </Row>
                    )}
                </PanelGroup>
            )}
        </aside>
    );
}

export default function ForPr({ pr, runs, pullRequest }: Props) {
    const form = useForm({});
    const [shownIds, setShownIds] = useState<Set<string>>(() => new Set(window.location.hash.startsWith('#run-') ? [window.location.hash.slice(5)] : []));
    const [logTarget, setLogTarget] = useState<RunLogTarget | null>(null);
    const hasLiveRun = runs.some((run) => run.isLive);
    const poll = usePoll(5000, { only: ['runs', 'pullRequest'] }, { autoStart: false });
    const hasRuns = runs.length > 0;

    useEffect(() => {
        if (hasLiveRun) {
            poll.start();
        } else {
            poll.stop();
        }

        return () => poll.stop();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [hasLiveRun]);

    useEffect(() => {
        const reveal = () => {
            if (!window.location.hash.startsWith('#run-')) {
                return;
            }
            const id = window.location.hash.slice(5);
            setShownIds((previous) => new Set(previous).add(id));
            requestAnimationFrame(() => document.getElementById(`run-${id}`)?.scrollIntoView({ block: 'start' }));
        };

        reveal();
        window.addEventListener('hashchange', reveal);

        return () => window.removeEventListener('hashchange', reveal);
    }, []);

    const rerunReview = () => {
        form.post(rerun.url({ repoSlug: pr.repoSlug, prNumber: pr.number }), { preserveScroll: true });
    };

    const rerunReviewRef = useRef(rerunReview);
    rerunReviewRef.current = rerunReview;

    const actions = useMemo<PaletteAction[]>(
        () => [
            { id: 'review-open-pull-request', label: 'Open the pull request on GitHub', keywords: ['pr', 'github', 'pull request'], hint: 'new window', href: pr.url, run: () => window.open(pr.url, '_blank', 'noopener') },
            { id: 'review-rerun', label: 'Re-run this review', keywords: ['review', 'again', 'retry'], run: () => rerunReviewRef.current() },
        ],
        [pr.url],
    );
    useCommandPaletteActions('This review', actions);

    return (
        <>
            <Head title={`${pr.repoSlug}#${pr.number}`} />
            <PageHeader
                crumbs={['PR reviews', `${pr.repoSlug} #${pr.number}`]}
                actions={
                    <div className="flex items-center gap-2">
                        <Button variant="primary" pending={form.processing} onClick={rerunReview} data-testid="rerun-review">
                            {hasRuns ? 'Re-run review' : 'Review this pull request'}
                        </Button>
                        {pullRequest.preview !== null && (
                            <Button variant="secondary" onClick={() => window.open(pullRequest.preview!.url, '_blank', 'noopener')} data-testid="review-preview">
                                Preview
                            </Button>
                        )}
                        <Button variant="secondary" onClick={() => window.open(pr.url, '_blank', 'noopener')} data-testid="review-open-pr">
                            Open PR #{pr.number}
                            <ExternalLink size={11} />
                        </Button>
                    </div>
                }
            />

            <div className="border-b border-hair px-4 py-3 sm:px-5">
                <h1 className="truncate text-[15px] font-medium text-body">{pr.title}</h1>
            </div>

            <div className="min-h-0 flex-1 overflow-auto px-4 py-4 sm:px-5">
                <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_300px]">
                    <div className="space-y-3" data-testid="review-runs">
                        {hasRuns ? (
                            runs.map((run, index) => (
                                <RunCard
                                    key={run.id}
                                    run={run}
                                    pr={pr}
                                    isExpanded={index === 0 || shownIds.has(run.id)}
                                    onShow={() => setShownIds((previous) => new Set(previous).add(run.id))}
                                    onOpenLog={() => setLogTarget({ kind: 'url', id: run.logUrl, entryId: null, headline: `${run.scope === 'incremental' ? 'Incremental' : 'Full'} review at ${formatTime(run.startedAt)}` })}
                                />
                            ))
                        ) : (
                            <div className="rounded-card border border-hair bg-panel px-5 py-12 text-center text-[13px] text-muted shadow-card" data-testid="review-empty">
                                <p>Yak has not reviewed this pull request.</p>
                            </div>
                        )}
                    </div>
                    <SidePanel panel={pullRequest} />
                </div>
            </div>
            <RunLogOverlay target={logTarget} onClose={() => setLogTarget(null)} />
        </>
    );
}

ForPr.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
