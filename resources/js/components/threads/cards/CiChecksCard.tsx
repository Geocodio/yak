import { usePoll } from '@inertiajs/react';
import { Button } from '@geocodio/console-ui';
import { Check, CheckCircle2, ChevronDown, CircleAlert, ExternalLink, MinusCircle, RefreshCw, Sparkles, TriangleAlert, Wrench, XCircle } from 'lucide-react';
import { useEffect, useState } from 'react';
import { cardDraftKey, useCardDraft } from '@/components/threads/cards/useCardDraft';
import { formatSeconds } from '@/lib/formatSeconds';
import { useRouterAction } from '@/lib/useRouterAction';
import { retry } from '@/routes/threads';
import { rerun } from '@/routes/threads/ci';
import type { ThreadActions, ThreadCiCheck, ThreadCiChecks } from '@/types/threads';

/** `1m 14s`, `42s` or `1h 5m` for a number of seconds, ticking to the second. */
function elapsed(seconds: number): string {
    const whole = Math.max(0, Math.floor(seconds));

    if (whole < 60) {
        return `${whole}s`;
    }

    return whole < 3600 ? `${Math.floor(whole / 60)}m ${whole % 60}s` : formatSeconds(whole);
}

function secondsSince(startedAt: string | null, now: number): number {
    return startedAt === null ? 0 : (now - Date.parse(startedAt)) / 1000;
}

function useNow(isTicking: boolean): number {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        if (!isTicking) {
            return undefined;
        }
        const timer = window.setInterval(() => setNow(Date.now()), 1000);

        return () => window.clearInterval(timer);
    }, [isTicking]);

    return now;
}

function pullRequestNumber(url: string | null): string | null {
    return url?.match(/\/pull\/(\d+)/)?.[1] ?? null;
}

function checksUrl(ciChecks: ThreadCiChecks): string | null {
    return ciChecks.pullRequestUrl === null ? null : `${ciChecks.pullRequestUrl}/checks`;
}

function SplitBar({ counts }: { counts: ThreadCiChecks['counts'] }) {
    const parts = [
        { count: counts.failing, className: 'bg-fail' },
        { count: counts.running, className: 'bg-warn ci-stripes' },
        { count: counts.passed, className: 'bg-ok' },
        { count: counts.skipped, className: 'bg-muted/45' },
    ];

    return (
        <div className="mx-3.5 mb-3 flex h-1.5 gap-0.5 overflow-hidden rounded-full" aria-hidden>
            {parts.filter((part) => part.count > 0).map((part) => (
                <div key={part.className} className={part.className} style={{ flex: part.count }} />
            ))}
        </div>
    );
}

function DefaultBranchPill({ check, defaultBranch }: { check: ThreadCiCheck; defaultBranch: string }) {
    if (check.alsoFailsOnDefaultBranch === null) {
        return null;
    }

    return (
        <span className={`whitespace-nowrap rounded-full px-2 py-0.5 text-[11.5px] font-medium ${check.alsoFailsOnDefaultBranch ? 'bg-warn-soft text-warn' : 'bg-fail-soft text-fail'}`}>
            {check.alsoFailsOnDefaultBranch ? `Also fails on ${defaultBranch}` : `Passes on ${defaultBranch}`}
        </span>
    );
}

function FinishedRow({ check }: { check: ThreadCiCheck }) {
    const Icon = check.status === 'passed' ? CheckCircle2 : check.status === 'skipped' ? MinusCircle : XCircle;
    const tone = check.status === 'passed' ? 'text-ok' : check.status === 'skipped' ? 'text-muted' : 'text-fail';

    return (
        <div className="flex items-center gap-2.5" data-testid="ci-check-row" data-status={check.status}>
            <Icon className={`size-4 shrink-0 ${tone}`} aria-label={check.status} role="img" />
            <span className="min-w-0 flex-1 truncate text-[13px]">{check.name}</span>
            {check.durationSeconds !== null && <span className="text-[12px] tabular-nums text-muted">{formatSeconds(check.durationSeconds)}</span>}
            {check.url !== null && <a href={check.url} target="_blank" rel="noreferrer" className="text-[12.5px] text-accent-text hover:underline">Details</a>}
        </div>
    );
}

function RunningRow({ check, now }: { check: ThreadCiCheck; now: number }) {
    return (
        <div className="flex items-center gap-2.5" data-testid="ci-check-row" data-status="running">
            <span className="relative flex size-2.5 shrink-0 items-center justify-center ci-pulse" aria-hidden>
                <span className="relative size-2.5 rounded-full bg-warn" />
            </span>
            <span className="min-w-0 flex-1 truncate text-[13px]">{check.name}</span>
            <span className="text-[12px] tabular-nums text-muted">Running for {elapsed(secondsSince(check.startedAt, now))}</span>
            {check.url !== null && <a href={check.url} target="_blank" rel="noreferrer" className="text-[12.5px] text-accent-text hover:underline">Details</a>}
        </div>
    );
}

function RunningCard({ ciChecks }: { ciChecks: ThreadCiChecks }) {
    const now = useNow(true);
    const { counts } = ciChecks;
    const startedAt = ciChecks.checks.map((check) => check.startedAt).filter((value): value is string => value !== null).sort()[0] ?? null;
    const summary = [`${counts.running} running`, counts.failing > 0 ? `${counts.failing} failing` : null, `${counts.passed} passed`, counts.skipped > 0 ? `${counts.skipped} skipped` : null, startedAt !== null ? `started ${elapsed(secondsSince(startedAt, now))} ago` : null].filter(Boolean).join(' · ');
    const link = checksUrl(ciChecks);

    return (
        <section className="overflow-hidden rounded-card border border-border bg-panel" aria-labelledby="ci-title" data-testid="card-ci-checks" data-state="running">
            <div className="flex items-center justify-between gap-3 px-3.5 pb-2.5 pt-3">
                <h2 id="ci-title" className="flex flex-wrap items-baseline gap-x-2 text-[14px] font-semibold">
                    Checks
                    <span className="text-[13px] font-normal tabular-nums text-muted" data-testid="ci-summary">{summary}</span>
                </h2>
                {link !== null && <a href={link} target="_blank" rel="noreferrer" className="flex items-center gap-1 whitespace-nowrap text-[12.5px] text-accent-text hover:underline">#{pullRequestNumber(ciChecks.pullRequestUrl)} on GitHub<ExternalLink className="size-3" aria-hidden /></a>}
            </div>
            <SplitBar counts={counts} />
            <div className="flex flex-col gap-2.5 border-t border-border px-3.5 py-3">
                {ciChecks.checks.map((check) => (check.status === 'running' ? <RunningRow key={check.name} check={check} now={now} /> : <FinishedRow key={check.name} check={check} />))}
            </div>
            <p className="border-t border-border bg-panel-2 px-3.5 py-2.5 text-[12.5px] text-muted">Yak opens the pull request for review when these pass, and reads the logs of any that fail.</p>
        </section>
    );
}

function FinishedCard({ threadId, ciChecks, actions, onShowLog }: { threadId: string; ciChecks: ThreadCiChecks; actions: ThreadActions; onShowLog: (() => void) | null }) {
    const action = useRouterAction();
    const [error, setError] = useCardDraft<string | null>(cardDraftKey(threadId, 'ci_checks', 'error'), null);
    const [isPassedShown, setIsPassedShown] = useState(false);
    const { counts, diagnosis, defaultBranch } = ciChecks;
    const failing = ciChecks.checks.filter((check) => check.status === 'failed');
    const settled = ciChecks.checks.filter((check) => check.status !== 'failed');
    const isShared = ciChecks.sharedWithDefaultBranch;
    const link = checksUrl(ciChecks);
    const names = failing.length === 1 ? failing[0].name : `${failing.length} checks`;
    const title = isShared ? 'CI is red, but not because of this change' : failing.length === 1 ? '1 check fails on this change' : `${failing.length} checks fail on this change`;
    const isAllFromBranch = failing.every((check) => check.alsoFailsOnDefaultBranch === false);
    const isCompared = failing.every((check) => check.alsoFailsOnDefaultBranch !== null);
    const subtitle = isShared
        ? `${names} ${failing.length === 1 ? 'fails' : 'fail'} on ${defaultBranch} too, so Yak left the branch as it is.${ciChecks.pullRequestUrl !== null ? ' The pull request is ready for review.' : ''}`
        : isAllFromBranch
          ? `${failing.length === 1 ? 'It passes' : 'They pass'} on ${defaultBranch}, so this branch caused ${failing.length === 1 ? 'it' : 'them'}.`
          : isCompared
            ? `Some of these also fail on ${defaultBranch}. The others come from this branch.`
            : `Yak could not compare these with ${defaultBranch}.`;
    const Icon = isShared ? TriangleAlert : CircleAlert;
    const settledSummary = [counts.passed > 0 ? `${counts.passed} passed` : null, counts.skipped > 0 ? `${counts.skipped} skipped` : null].filter(Boolean).join(', ');

    const run = (key: string, url: string, fallback: string) =>
        action.run(key, 'post', url, {}, {
            onStart: () => setError(null),
            onError: (errors) => setError(Object.values(errors)[0] ?? fallback),
        });

    return (
        <section className={`flex flex-col gap-3.5 rounded-card px-5 pb-5 pt-4 ${isShared ? 'bg-warn-soft' : 'bg-fail-soft'}`} aria-labelledby="ci-title" data-testid="card-ci-checks" data-state="failed" data-shared={isShared}>
            <div className="flex flex-col gap-1">
                <h2 id="ci-title" className="flex items-center gap-2 text-[15px] font-semibold">
                    <Icon className={`size-4 ${isShared ? 'text-warn' : 'text-fail'}`} aria-hidden />
                    {title}
                </h2>
                <p className="text-[13px] leading-normal" data-testid="ci-subtitle">{subtitle}</p>
            </div>

            <div className="overflow-hidden rounded-control border border-border bg-panel">
                <div className="flex items-center justify-between gap-3 px-3.5 pb-2.5 pt-3">
                    <div className="flex flex-wrap items-baseline gap-x-2">
                        <span className="text-[14px] font-semibold">Checks</span>
                        <span className="text-[13px] text-muted" data-testid="ci-summary">{[`${counts.failing} failing`, `${counts.passed} passed`, counts.skipped > 0 ? `${counts.skipped} skipped` : null].filter(Boolean).join(' · ')}</span>
                    </div>
                    {link !== null && <a href={link} target="_blank" rel="noreferrer" className="flex items-center gap-1 whitespace-nowrap text-[12.5px] text-accent-text hover:underline">#{pullRequestNumber(ciChecks.pullRequestUrl)} on GitHub<ExternalLink className="size-3" aria-hidden /></a>}
                </div>
                <SplitBar counts={counts} />

                <div className="flex flex-col gap-2.5 border-t border-border px-3.5 py-3">
                    {failing.map((check) => (
                        <div key={check.name} className="flex flex-wrap items-center gap-x-2.5 gap-y-1" data-testid="ci-check-row" data-status="failed">
                            <XCircle className="size-4 shrink-0 text-fail" role="img" aria-label="Failed" />
                            <div className="flex min-w-0 flex-1 flex-wrap items-baseline gap-x-2">
                                <span className="text-[13.5px] font-medium">{check.name}</span>
                                <span className="text-[12.5px] text-muted">{[check.durationSeconds !== null ? `Failed after ${formatSeconds(check.durationSeconds)}` : 'Failed', check.title].filter(Boolean).join(' · ')}</span>
                            </div>
                            <DefaultBranchPill check={check} defaultBranch={defaultBranch} />
                            {check.url !== null && <a href={check.url} target="_blank" rel="noreferrer" className="whitespace-nowrap text-[12.5px] text-accent-text hover:underline">Details</a>}
                        </div>
                    ))}

                    {diagnosis !== null ? (
                        <div className="ml-6 flex flex-col gap-2.5 rounded-control bg-panel-2 px-3.5 py-3 max-sm:ml-0" data-testid="ci-diagnosis">
                            <div className="flex items-center gap-1.5 text-[12px] font-semibold uppercase tracking-wide text-muted">
                                <Sparkles className="size-3.5" aria-hidden />
                                Why it failed
                            </div>
                            <p className="text-[13px] leading-relaxed">{diagnosis.why}</p>
                            {(diagnosis.next !== '' || ciChecks.canRerun || actions.canRetry) && (
                                <div className="flex flex-col gap-1.5">
                                    <span className="text-[12px] font-semibold uppercase tracking-wide text-muted">What you can do</span>
                                    {diagnosis.next !== '' && <p className="text-[13px] leading-relaxed">{diagnosis.next}</p>}
                                </div>
                            )}
                        </div>
                    ) : (
                        <p className="ml-6 text-[12.5px] text-muted max-sm:ml-0" data-testid="ci-no-diagnosis">Open Details to read the log.</p>
                    )}
                </div>

                {settled.length > 0 && (
                    <>
                        <button type="button" className="flex w-full items-center gap-2 border-t border-border bg-panel-2 px-3.5 py-2.5 text-left text-[13px] text-muted" aria-expanded={isPassedShown} onClick={() => setIsPassedShown((shown) => !shown)} data-testid="ci-passed-toggle">
                            <ChevronDown className={`size-3.5 transition-transform ${isPassedShown ? 'rotate-180' : ''}`} aria-hidden />
                            <Check className="size-3.5 text-ok" aria-hidden />
                            {settledSummary}
                        </button>
                        {isPassedShown && (
                            <div className="flex flex-col gap-2 border-t border-border px-3.5 py-3">
                                {settled.map((check) => <FinishedRow key={check.name} check={check} />)}
                            </div>
                        )}
                    </>
                )}
            </div>

            {error !== null && <p role="alert" id="ci-error" className="rounded-control bg-fail-soft px-3 py-2 text-[13px]" data-testid="ci-error">{error}</p>}
            <div className="flex flex-wrap gap-2 max-sm:flex-col">
                {!isShared && actions.canRetry && (
                    <Button variant="primary" className="max-sm:h-11 max-sm:w-full" icon={<Wrench size={13} />} pending={action.isPending('retry')} pendingLabel="Starting…" disabled={action.busy} onClick={() => run('retry', retry.url(threadId), 'Yak could not retry. Try again.')} aria-describedby={error !== null ? 'ci-error' : undefined} data-testid="ci-retry">
                        Let Yak fix it
                    </Button>
                )}
                {ciChecks.canRerun && (
                    <Button variant={isShared ? 'primary' : undefined} className="max-sm:h-11 max-sm:w-full" icon={<RefreshCw size={13} />} pending={action.isPending('rerun')} pendingLabel="Re-running…" disabled={action.busy} onClick={() => run('rerun', rerun.url(threadId), 'GitHub could not re-run the checks. Try again.')} aria-describedby={error !== null ? 'ci-error' : undefined} data-testid="ci-rerun">
                        Re-run failed checks
                    </Button>
                )}
                {isShared && actions.canRetry && (
                    <Button className="max-sm:h-11 max-sm:w-full" icon={<Wrench size={13} />} pending={action.isPending('retry')} pendingLabel="Starting…" disabled={action.busy} onClick={() => run('retry', retry.url(threadId), 'Yak could not retry. Try again.')} data-testid="ci-retry">
                        Let Yak fix it anyway
                    </Button>
                )}
                {onShowLog !== null && (
                    <Button className="max-sm:h-11 max-sm:w-full" onClick={onShowLog} data-testid="ci-show-log">Show the failing step</Button>
                )}
            </div>
        </section>
    );
}

/** The GitHub checks behind a thread that waits on or stopped at CI: running, red because of the branch, or red on the default branch too. */
export function CiChecksCard({ threadId, ciChecks, actions, onShowLog }: { threadId: string; ciChecks: ThreadCiChecks; actions: ThreadActions; onShowLog: (() => void) | null }) {
    const isRunning = ciChecks.state === 'running';
    const poll = usePoll(10_000, { only: ['ciChecks', 'card', 'stopped', 'actions'] }, { autoStart: false });

    // A re-run turns a finished card back into a running one, so polling follows the state, not the first render.
    useEffect(() => {
        if (!isRunning) {
            return undefined;
        }

        poll.start();

        return poll.stop;
    }, [isRunning]);

    return ciChecks.state === 'running' ? <RunningCard ciChecks={ciChecks} /> : <FinishedCard threadId={threadId} ciChecks={ciChecks} actions={actions} onShowLog={onShowLog} />;
}
