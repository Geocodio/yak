import { Skeleton } from '@geocodio/console-ui';
import { CopyButton } from '@/components/threads/environment/LinkActions';
import type { PanelProps } from '@/threads/panels';
import { OverviewGroup, OverviewList, OverviewRow } from './OverviewRow';

function duration(seconds: number): string {
    if (seconds < 60) {
        return `${seconds}s`;
    }
    const minutes = Math.round(seconds / 60);

    return minutes < 60 ? `${minutes}m` : `${Math.floor(minutes / 60)}h ${minutes % 60}m`;
}

function shortSession(sessionId: string): string {
    return sessionId.length > 12 ? `${sessionId.slice(0, 4)}…${sessionId.slice(-4)}` : sessionId;
}

const plural = (count: number, word: string) => `${count} ${word}${count === 1 ? '' : 's'}`;

/** What the runs of the thread took: turns, attempts, time, cost, the session and the error log of a failed run. */
export function RunDetailsGroup({ runDetails }: PanelProps) {
    if (runDetails === undefined) {
        return (
            <OverviewGroup title="Run details" testId="overview-run-details-loading">
                <div className="flex flex-col gap-3">
                    <Skeleton className="h-4 w-2/3" />
                    <Skeleton className="h-4 w-1/2" />
                    <Skeleton className="h-4 w-3/5" />
                </div>
            </OverviewGroup>
        );
    }

    if (runDetails.runCount === 0) {
        return (
            <OverviewGroup title="Run details" testId="overview-run-details">
                <p className="text-[13px] text-muted" data-testid="run-details-empty">
                    Nothing has run yet.
                </p>
            </OverviewGroup>
        );
    }

    return (
        <OverviewGroup title="Run details" testId="overview-run-details">
            <OverviewList>
                <OverviewRow label="Turns" testId="run-details-turns">
                    {plural(runDetails.runCount, 'run')}, {plural(runDetails.stepCount, 'step')}
                </OverviewRow>
                {runDetails.attempts !== null && (
                    <OverviewRow label="Attempts" testId="run-details-attempts">
                        {runDetails.attempts}
                    </OverviewRow>
                )}
                <OverviewRow label="Duration" testId="run-details-duration">
                    {duration(runDetails.durationSeconds)}
                </OverviewRow>
                <OverviewRow label="Cost" testId="run-details-cost" tip="What the runs cost on the Claude account. Spend billed to the API is shown when there is any.">
                    ${runDetails.costUsd.toFixed(2)}
                    {runDetails.apiSpendUsd > 0 && <span className="text-muted"> · ${runDetails.apiSpendUsd.toFixed(2)} on the API</span>}
                </OverviewRow>
                {runDetails.sessionId !== null && (
                    <OverviewRow label="Session" testId="run-details-session">
                        <code className="font-mono text-[12px]" title={runDetails.sessionId}>
                            {shortSession(runDetails.sessionId)}
                        </code>
                        <CopyButton text={runDetails.sessionId} label="session id" />
                    </OverviewRow>
                )}
            </OverviewList>
            {runDetails.errorLog !== null && (
                <details className="mt-3" data-testid="run-details-error-log">
                    <summary className="cursor-pointer text-[12.5px] text-fail">Error log</summary>
                    <pre className="mt-1.5 max-h-60 overflow-auto rounded-card border border-hair bg-panel-2 p-2.5 font-mono text-[11.5px] leading-relaxed whitespace-pre-wrap [overflow-wrap:anywhere]">{runDetails.errorLog}</pre>
                    {runDetails.errorLogTruncated && <p className="mt-1 text-[12px] text-muted">The log is cut here. The full text is in the session.</p>}
                </details>
            )}
        </OverviewGroup>
    );
}
