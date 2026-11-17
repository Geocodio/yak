import { router, usePoll } from '@inertiajs/react';
import { Button, StackedTable, StackedTbody, StackedTd, StackedThead, StackedTr, StatusPill, Th, Tr } from '@geocodio/console-ui';
import { CircleAlert, RefreshCw } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Callout } from '@/components/riskProfile/RiskProfileStates';
import { formatAgoLong } from '@/lib/format';
import type { ApprovalLevel, ApprovalsRepository, OpenPullRequests as OpenPullRequestsData } from '@/types/approvals';
import { outcomeAt, type PullRequestOutcome } from './levels';

const TONE: Record<PullRequestOutcome['kind'], 'ok' | 'idle' | 'warn' | 'fail' | 'info'> = { approve: 'ok', human: 'idle', waiting: 'warn', failed: 'fail', checking: 'info' };

// A check queued from here has not written any row yet, so the page polls for this long before giving up.
const AWAIT_CHECK_MS = 60_000;

export function OpenPullRequests({ repository, data, repositoryHolds, level }: { repository: ApprovalsRepository; data: OpenPullRequestsData; repositoryHolds: string[]; level: ApprovalLevel }) {
    const [awaiting, setAwaiting] = useState<{ since: number; checkedAt: string | null; error: string | null } | null>(null);
    const { start, stop } = usePoll(3000, { only: ['openPullRequests'] }, { autoStart: false, keepAlive: true });
    const hasChecked = data.checkedAt !== null || data.rows.length > 0;
    const isAwaiting = awaiting !== null && data.checkedAt === awaiting.checkedAt && data.error === awaiting.error;
    const isPolling = data.pending > 0 || isAwaiting;

    useEffect(() => {
        if (!isPolling) {
            return;
        }

        start();

        return stop;
    }, [isPolling]);

    useEffect(() => {
        if (awaiting === null) {
            return;
        }

        const timeout = window.setTimeout(() => setAwaiting(null), AWAIT_CHECK_MS);

        return () => window.clearTimeout(timeout);
    }, [awaiting]);

    const check = () => {
        setAwaiting({ since: Date.now(), checkedAt: data.checkedAt, error: data.error });
        router.post(repository.checkUrl, {}, { preserveScroll: true, onError: () => setAwaiting(null) });
    };
    const checkButton = (
        <Button data-testid="open-prs-check" icon={<RefreshCw size={14} aria-hidden="true" />} pending={isPolling} onClick={check}>
            {hasChecked ? 'Check open PRs again' : 'Check open PRs'}
        </Button>
    );
    const outcomes = data.rows.map((row) => ({ row, outcome: outcomeAt(row, level, repositoryHolds) }));
    const count = (kind: PullRequestOutcome['kind']) => outcomes.filter((entry) => entry.outcome.kind === kind).length;

    return (
        <section data-testid="open-prs" aria-labelledby="open-prs-heading" className="flex flex-col gap-3.5 rounded-card border border-hair bg-panel p-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0 flex-1 basis-[340px]">
                    <h2 id="open-prs-heading" className="font-mono text-[13px] tracking-[0.18em]">OPEN PULL REQUESTS AT DEFCON {level}</h2>
                    <p className="mt-1 text-[14px] text-muted">Checked without posting anything. Each result is for the commit checked; a new push changes it.</p>
                </div>
                <div className="flex flex-col items-end gap-1">
                    {checkButton}
                    {data.checkedAt && (
                        <span data-testid="open-prs-checked" className="text-[12px] text-muted">
                            Checked {formatAgoLong(data.checkedAt)} against concerns on {repository.defaultBranch}
                        </span>
                    )}
                </div>
            </div>

            {data.error && (
                <div data-testid="open-prs-error">
                    <Callout tone="fail" icon={<CircleAlert size={16} aria-hidden="true" className="text-fail" />}>
                        <p className="font-semibold">The last check could not finish.</p>
                        <p className="text-muted">{data.error}</p>
                        <Button onClick={check} pending={isPolling}>Check again</Button>
                    </Callout>
                </div>
            )}

            {!hasChecked && !data.error && <p className="text-[14px] text-muted">Yak has not checked this repository's open pull requests yet. The check does not post anything.</p>}
            {hasChecked && !data.error && data.rows.length === 0 && <p data-testid="open-prs-empty" className="text-[14px] text-muted">There are no open pull requests.</p>}

            {data.rows.length > 0 && (
                <>
                    <div className="flex flex-wrap gap-2 text-[13px] font-semibold">
                        <span className="rounded-full border border-hair bg-ok-soft px-2.5 py-1 text-ok">{count('approve')} would be approved</span>
                        <span className="rounded-full border border-hair bg-panel-2 px-2.5 py-1 text-muted">{count('human')} go to a human</span>
                        <span className="rounded-full border border-hair bg-warn-soft px-2.5 py-1 text-warn">{count('waiting')} waiting for Yak's review</span>
                    </div>
                    <div className="md:overflow-x-auto rounded-control border border-hair">
                        <StackedTable className="w-full">
                            <StackedThead>
                                <Tr>
                                    <Th>Pull request</Th>
                                    <Th>Result</Th>
                                    <Th>Why</Th>
                                </Tr>
                            </StackedThead>
                            <StackedTbody>
                                {outcomes.map(({ row, outcome }) => (
                                    <StackedTr key={row.number} data-testid={`open-pr-row-${row.number}`}>
                                        <StackedTd label="Pull request" className="md:min-w-[260px]">
                                            <a href={row.url} target="_blank" rel="noopener noreferrer" className="font-semibold hover:text-accent-text hover:underline">#{row.number}</a> {row.title}
                                            <div className="text-[12px] text-muted">
                                                {row.author} · <span className="font-mono">{row.headSha.slice(0, 7)}</span>
                                                {row.yakReviewUrl && <> · <a href={row.yakReviewUrl} className="hover:text-accent-text hover:underline">Yak's review</a></>}
                                            </div>
                                        </StackedTd>
                                        <StackedTd label="Result" className="md:min-w-[150px] md:whitespace-nowrap">
                                            <StatusPill tone={TONE[outcome.kind]} label={outcome.label} />
                                        </StackedTd>
                                        <StackedTd label="Why" className="md:min-w-[260px]">
                                            <span data-testid={`open-pr-reason-${row.number}`}>{outcome.reason}</span>
                                            {outcome.checks.length > 1 && (
                                                <details className="mt-1">
                                                    <summary className="cursor-pointer text-[12px] text-muted">All checks</summary>
                                                    <ul className="mt-1 list-disc pl-[18px] text-[12px] text-muted">
                                                        {outcome.checks.map((hold) => <li key={hold}>{hold}</li>)}
                                                    </ul>
                                                </details>
                                            )}
                                            {outcome.kind === 'waiting' && (
                                                <div className="mt-1.5">
                                                    <Button data-testid={`open-pr-review-${row.number}`} onClick={() => router.post(row.reviewNowUrl, {}, { preserveScroll: true })}>Review now</Button>
                                                </div>
                                            )}
                                        </StackedTd>
                                    </StackedTr>
                                ))}
                            </StackedTbody>
                        </StackedTable>
                    </div>
                </>
            )}
            <p className="text-[12px] text-muted">Each pull request costs two model calls unless Yak checked it at this commit before.</p>
        </section>
    );
}
