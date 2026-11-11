import { useState } from 'react';
import { csrfToken } from '@/lib/csrfToken';
import { fix, order } from '@/routes/threads/pull-requests';
import type { PanelProps } from '@/threads/panels';
import { PullRequestCard } from './PullRequestCard';
import { moveRow } from './pullRequestsState';
import { usePullRequests } from './usePullRequests';

const BANNER_CLASS = {
    info: 'bg-info-soft',
    danger: 'bg-fail-soft',
    success: 'bg-ok-soft',
} as const;

function send(url: string, method: 'PUT' | 'POST', body: unknown): Promise<Response> {
    return fetch(url, {
        method,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': csrfToken() },
        body: JSON.stringify(body),
    });
}

export function PullRequestsPanel({ thread }: PanelProps) {
    const { data, isLoading, error, reload } = usePullRequests(thread);
    const [isBusy, setIsBusy] = useState(false);
    const [failure, setFailure] = useState<string | null>(null);

    if (data === null) {
        return (
            <p className="text-[13px] text-muted" data-testid="pull-requests-panel">
                {isLoading ? 'Loading pull requests' : (error ?? 'The pull requests could not be loaded.')}
            </p>
        );
    }

    const run = (request: Promise<Response>, message: string) => {
        setIsBusy(true);
        setFailure(null);
        request
            .then((response) => {
                if (!response.ok) {
                    throw new Error(message);
                }
            })
            .catch((caught: unknown) => setFailure(caught instanceof Error ? caught.message : message))
            .finally(() => {
                setIsBusy(false);
                reload();
            });
    };

    const ids = data.pullRequests.map((row) => row.rowId);
    const move = (rowId: number, step: -1 | 1) => run(send(order.url(thread.id), 'PUT', { order: moveRow(ids, rowId, step) }), 'The order could not be saved.');
    const askToFix = (rowId: number) => run(send(fix.url({ thread: thread.id, repository: rowId }), 'POST', {}), 'Claude could not be asked to fix it.');

    return (
        <div className="flex flex-col gap-4" data-testid="pull-requests-panel" data-state={data.state}>
            <div className={`rounded-card px-3 py-2.5 ${BANNER_CLASS[data.banner.tone]}`} data-testid="pull-requests-banner">
                <p className="text-[13px] font-semibold text-body">{data.banner.title}</p>
                <p className="mt-0.5 text-[12.5px] text-muted">{data.banner.body}</p>
            </div>

            {data.totalCount > 0 && (
                <div className="flex items-baseline justify-between gap-2">
                    <h3 className="text-[13px] font-semibold text-body">
                        Merge order <span className="font-normal text-muted">{data.openCount} open of {data.totalCount}</span>
                    </h3>
                </div>
            )}

            {failure && <p className="text-[12.5px] text-fail" role="alert">{failure}</p>}

            {data.pullRequests.length > 0 && (
                <ol className="flex flex-col gap-3">
                    {data.pullRequests.map((row, index) => (
                        <PullRequestCard
                            key={row.rowId}
                            row={row}
                            position={index + 1}
                            isFirst={index === 0}
                            isLast={index === data.pullRequests.length - 1}
                            canDrive={data.canDrive}
                            isBusy={isBusy}
                            onMove={(step) => move(row.rowId, step)}
                            onAskToFix={() => askToFix(row.rowId)}
                        />
                    ))}
                </ol>
            )}

            {data.withoutPullRequest.map((entry) => (
                <div key={entry.repo} className="rounded-card border border-dashed border-hair px-3 py-2.5" data-testid={`without-pull-request-${entry.repo}`} data-reason={entry.reason}>
                    <div className="flex items-center gap-2">
                        <span className="font-mono text-[12.5px] text-body">{entry.repo}</span>
                        <span className="rounded-pill border border-hair bg-panel-2 px-2 py-0.5 text-[11px] text-muted">{entry.role === 'context' ? 'Context' : 'Changes'}</span>
                    </div>
                    <p className="mt-1 text-[12.5px] text-muted">{entry.note}</p>
                </div>
            ))}
        </div>
    );
}
