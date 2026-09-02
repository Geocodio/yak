import { router } from '@inertiajs/react';
import { Badge, Table, Tbody, Td, Th, Thead, Tooltip, Tr, cn } from '@geocodio/console-ui';
import { ChevronDown, GitPullRequest, Globe, MessageSquare, ShieldAlert, Terminal, Zap } from 'lucide-react';
import type { ComponentType } from 'react';
import { show as showTask } from '@/routes/tasks';
import { StatusDot } from '@/components/StatusDot';
import type { TaskRow } from '@/types/tasks';

const SOURCE_ICON: Record<string, ComponentType<{ size?: number; className?: string }>> = {
    slack: MessageSquare,
    sentry: ShieldAlert,
    linear: Zap,
};

const PR_TONE = { open: 'ok', merged: 'accent', closed: 'fail' } as const;

function NestedFollowUps({ items }: { items: TaskRow['followUps'] }) {
    return (
        <ol className="mb-1 mt-3 flex flex-col gap-2 border-l-2 border-hair-strong pl-3">
            {items.map((child) => (
                <li key={child.id} className="flex items-center gap-2.5 text-[12px]">
                    <StatusDot status={child.status} />
                    <span className={cn('truncate', child.status === 'failed' ? 'text-body' : 'text-muted')}>{child.description}</span>
                    {child.status === 'failed' && <Badge tone="fail">Failed</Badge>}
                    <span className="tnum ml-auto shrink-0 pl-3 text-faint">{child.createdAgo}</span>
                </li>
            ))}
        </ol>
    );
}

function TaskTableRow({ task, onPreview }: { task: TaskRow; onPreview: (src: string | null) => void }) {
    const hasFollowUps = task.followUps.length > 0;
    const SourceIcon = SOURCE_ICON[task.source] ?? Terminal;

    return (
        <Tr
            interactive
            data-testid={`task-row-${task.id}`}
            onClick={() => router.visit(showTask.url(task.id))}
            onMouseEnter={() => {
                if (task.previewGif && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                    onPreview(task.previewGif);
                }
            }}
            onMouseLeave={() => onPreview(null)}
        >
            <Td className={cn('w-8 pl-4', hasFollowUps && 'align-top pt-3')}>
                <StatusDot status={task.status} />
            </Td>
            <Td className={cn('w-[110px] whitespace-nowrap text-muted', hasFollowUps && 'align-top pt-2.5')}>
                <span className="flex items-center gap-1.5">
                    <SourceIcon size={13} className="text-faint" />
                    {task.sourceLabel}
                </span>
            </Td>
            <Td className={cn('w-[90px] whitespace-nowrap', hasFollowUps && 'align-top pt-2.5')}>{task.by ?? '—'}</Td>
            <Td className={cn('w-[170px] whitespace-nowrap font-mono text-[12px] text-muted', hasFollowUps && 'align-top pt-2.5')}>
                {task.repoUrl ? (
                    <a
                        href={task.repoUrl}
                        target={task.repoUrl === task.pr?.url ? '_blank' : undefined}
                        rel={task.repoUrl === task.pr?.url ? 'noopener noreferrer' : undefined}
                        onClick={(e) => e.stopPropagation()}
                        className="relative hover:text-accent-text hover:underline"
                    >
                        {task.repo}
                    </a>
                ) : (
                    (task.repo ?? '—')
                )}
            </Td>
            <Td className={cn('max-w-0', hasFollowUps && 'py-3')}>
                <div className="flex items-center gap-2">
                    {task.previewUrl && (
                        <a
                            href={showTask.url(task.id, { query: { t: 0 } })}
                            onClick={(e) => e.stopPropagation()}
                            aria-label={`Open the walkthrough for task ${task.externalId ?? task.id}`}
                            data-testid={`task-preview-${task.id}`}
                            className="relative shrink-0"
                        >
                            <img src={task.previewUrl} alt="" loading="lazy" className="h-8 w-14 rounded-control border border-hair object-cover" />
                        </a>
                    )}
                    <div className="min-w-0 flex-1">
                        <div className="flex items-center gap-2">
                            <span className="truncate">{task.description}</span>
                            {hasFollowUps && (
                                <Badge tone="neutral" className="whitespace-nowrap">
                                    {task.followUps.length} follow-ups
                                </Badge>
                            )}
                        </div>
                        {task.externalId &&
                            (task.externalUrl ? (
                                <a
                                    href={task.externalUrl}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    onClick={(e) => e.stopPropagation()}
                                    className="mt-0.5 truncate font-mono text-[11px] text-faint hover:text-accent-text hover:underline"
                                >
                                    {task.externalId}
                                </a>
                            ) : (
                                <div className="mt-0.5 truncate font-mono text-[11px] text-faint">{task.externalId}</div>
                            ))}
                    </div>
                </div>
                {hasFollowUps && <NestedFollowUps items={task.followUps} />}
            </Td>
            <Td className={cn('w-[130px] whitespace-nowrap', hasFollowUps && 'align-top pt-2.5')}>
                {task.pr ? (
                    <span className="flex items-center gap-2">
                        <a href={task.pr.url ?? undefined} target="_blank" rel="noopener noreferrer" onClick={(e) => e.stopPropagation()}>
                            <Badge tone={PR_TONE[task.pr.state]}>
                                <GitPullRequest size={11} className="mr-1 inline" />
                                {task.pr.number ? `#${task.pr.number}` : task.pr.state}
                            </Badge>
                        </a>
                        {task.deploymentUrl && (
                            <Tooltip label="Open the branch preview">
                                <a
                                    href={task.deploymentUrl}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    onClick={(e) => e.stopPropagation()}
                                    aria-label={`Open the branch preview for task ${task.externalId ?? task.id}`}
                                    className="text-faint hover:text-accent-text"
                                >
                                    <Globe size={13} />
                                </a>
                            </Tooltip>
                        )}
                    </span>
                ) : (
                    <span className="text-faint">—</span>
                )}
            </Td>
            <Td className={cn('tnum w-[80px] whitespace-nowrap text-right text-muted', hasFollowUps && 'align-top pt-2.5')}>
                {task.cost ?? '—'}
            </Td>
            <Td className={cn('tnum w-[100px] whitespace-nowrap pr-4 text-right text-muted', hasFollowUps && 'align-top pt-2.5')}>
                <Tooltip label={task.createdTooltip}>
                    <span>{task.createdAgo}</span>
                </Tooltip>
            </Td>
        </Tr>
    );
}

function SortHeader({
    column,
    label,
    sort,
    direction,
    onSort,
    className,
}: {
    column: string;
    label: string;
    sort: string;
    direction: 'asc' | 'desc';
    onSort: (column: string) => void;
    className?: string;
}) {
    const active = sort === column;
    return (
        <Th className={className}>
            <button
                type="button"
                onClick={() => onSort(column)}
                className="inline-flex items-center gap-1 hover:text-body"
            >
                {label}
                {active && <ChevronDown size={11} className={direction === 'asc' ? 'rotate-180' : ''} />}
            </button>
        </Th>
    );
}

export function TaskTable({
    tasks,
    sort,
    direction,
    onSort,
    onPreview,
}: {
    tasks: TaskRow[];
    sort: string;
    direction: 'asc' | 'desc';
    onSort: (column: string) => void;
    onPreview: (src: string | null) => void;
}) {
    return (
        <Table className="w-full">
            <Thead className="sticky top-0 z-10 bg-app">
                <Tr>
                    <Th className="pl-4">
                        <span className="sr-only">Status</span>
                    </Th>
                    <SortHeader column="source" label="Source" sort={sort} direction={direction} onSort={onSort} />
                    <SortHeader column="author_name" label="By" sort={sort} direction={direction} onSort={onSort} />
                    <SortHeader column="repo" label="Repo" sort={sort} direction={direction} onSort={onSort} />
                    <Th>Description</Th>
                    <Th>PR</Th>
                    <Th className="text-right">Cost</Th>
                    <SortHeader
                        column="created_at"
                        label="Created"
                        sort={sort}
                        direction={direction}
                        onSort={onSort}
                        className="pr-4 text-right"
                    />
                </Tr>
            </Thead>
            <Tbody>
                {tasks.map((task) => (
                    <TaskTableRow key={task.id} task={task} onPreview={onPreview} />
                ))}
            </Tbody>
        </Table>
    );
}
