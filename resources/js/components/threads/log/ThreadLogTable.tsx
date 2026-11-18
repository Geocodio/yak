import { Link } from '@inertiajs/react';
import { Badge, StackedTable, StackedTbody, StackedTd, StackedThead, StackedTr, Th, Tr, cn } from '@geocodio/console-ui';
import { ChevronDown } from 'lucide-react';
import { SourceLink } from '@/components/threads/header/SourceLink';
import { formatUsd, formatWhen } from '@/lib/format';
import { rowLinkHandlers } from '@/lib/visitRow';
import type { ThreadLogRow, ThreadLogSort, ThreadLogTone } from '@/types/threadLog';

const DOT: Record<ThreadLogTone, string> = { ok: 'bg-ok', warn: 'bg-warn', bad: 'bg-fail', info: 'bg-info', neutral: 'bg-idle' };
const PULL_REQUEST_TONE = { open: 'ok', merged: 'info', closed: 'fail', held: 'warn' } as const;
const PULL_REQUEST_LABEL = { open: 'Open', merged: 'Merged', closed: 'Closed', held: 'Held' } as const;

function PullRequestBadge({ state }: { state: string }) {
    const known = state in PULL_REQUEST_TONE;

    return <Badge tone={known ? PULL_REQUEST_TONE[state as keyof typeof PULL_REQUEST_TONE] : 'neutral'}>{known ? PULL_REQUEST_LABEL[state as keyof typeof PULL_REQUEST_LABEL] : state}</Badge>;
}

function PullRequestCell({ row }: { row: ThreadLogRow }) {
    const pullRequest = row.pullRequest;

    if (pullRequest === null) {
        return <span className="text-muted">{row.status.key === 'closed' ? 'None' : 'Not opened'}</span>;
    }

    return (
        <span className="flex items-center gap-1.5">
            {pullRequest.url !== null && pullRequest.number !== null && (
                <a href={pullRequest.url} target="_blank" rel="noreferrer" className="text-accent-text hover:underline" data-testid="thread-log-pull-request">
                    #{pullRequest.number}
                </a>
            )}
            <PullRequestBadge state={pullRequest.state} />
        </span>
    );
}

function SortHeader({ column, label, sort, direction, onSort, className }: { column: ThreadLogSort; label: string; sort: ThreadLogSort; direction: 'asc' | 'desc'; onSort: (column: ThreadLogSort) => void; className?: string }) {
    return (
        <Th className={className} aria-sort={sort === column ? (direction === 'asc' ? 'ascending' : 'descending') : undefined}>
            <button type="button" onClick={() => onSort(column)} className="inline-flex items-center gap-1 uppercase hover:text-body" data-testid={`sort-${column}`}>
                {label}
                {sort === column && <ChevronDown size={11} className={direction === 'asc' ? 'rotate-180' : ''} />}
            </button>
        </Th>
    );
}

/** The thread log as a table from `md` up and as stacked rows on a phone. Rows are links. */
export function ThreadLogTable({ rows, sort, direction, onSort }: { rows: ThreadLogRow[]; sort: ThreadLogSort; direction: 'asc' | 'desc'; onSort: (column: ThreadLogSort) => void }) {
    return (
        <StackedTable className="w-full" data-testid="thread-log-table">
            <StackedThead>
                <Tr>
                    <Th className="w-6" />
                    <Th>Thread</Th>
                    <SortHeader column="source" label="Source" sort={sort} direction={direction} onSort={onSort} />
                    <SortHeader column="starter" label="Started by" sort={sort} direction={direction} onSort={onSort} />
                    <SortHeader column="repo" label="Repo" sort={sort} direction={direction} onSort={onSort} />
                    <Th>Pull request</Th>
                    <Th className="text-right">Cost</Th>
                    <SortHeader column="last_activity" label="Last activity" sort={sort} direction={direction} onSort={onSort} className="text-right" />
                </Tr>
            </StackedThead>
            <StackedTbody id="thread-log-body">
                {rows.map((row) => (
                    <StackedTr key={row.id} className="cursor-pointer hover:bg-panel-2" data-testid={`thread-log-row-${row.id}`} data-status={row.status.key} {...rowLinkHandlers(row.url)}>
                        <StackedTd label="Status" className="w-6">
                            <span className={cn('inline-block size-2 rounded-full', DOT[row.status.tone])} role="img" aria-label={row.status.label} />
                        </StackedTd>
                        <StackedTd label="Thread" className="min-w-0 max-w-[420px]">
                            <Link href={row.url} className="block min-w-0" data-testid="thread-log-link">
                                <span className="block truncate text-[13px] font-semibold text-body">{row.title}</span>
                                <span className="block truncate text-[12px] text-muted" data-testid="thread-log-status-line">
                                    {row.statusLine}
                                </span>
                            </Link>
                        </StackedTd>
                        <StackedTd label="Source" className="[overflow-wrap:anywhere] text-[12px] md:whitespace-nowrap">
                            <SourceLink source={row.source} testId="thread-log-source" />
                        </StackedTd>
                        <StackedTd label="Started by" className="[overflow-wrap:anywhere] text-[12px] text-muted md:whitespace-nowrap">
                            {row.starter.name}
                        </StackedTd>
                        <StackedTd label="Repo" className="[overflow-wrap:anywhere] font-mono text-[12px] text-muted md:whitespace-nowrap">
                            {row.repo ?? '–'}
                        </StackedTd>
                        <StackedTd label="Pull request" className="whitespace-nowrap text-[12px]">
                            <PullRequestCell row={row} />
                        </StackedTd>
                        <StackedTd label="Cost" className="tnum whitespace-nowrap text-[12px] text-muted md:text-right">
                            {row.costUsd > 0 ? formatUsd(row.costUsd) : '–'}
                        </StackedTd>
                        <StackedTd label="Last activity" className="whitespace-nowrap text-[12px] text-muted md:text-right">
                            {formatWhen(row.lastActivityAt)}
                        </StackedTd>
                    </StackedTr>
                ))}
            </StackedTbody>
        </StackedTable>
    );
}
