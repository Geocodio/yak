import { Link } from '@inertiajs/react';
import { Badge, Tooltip, cn } from '@geocodio/console-ui';
import { ChevronRight, Lock } from 'lucide-react';
import { useState } from 'react';
import { formatTime } from '@/components/shell/formatTime';
import { ThreadStatusDot } from '@/components/shell/ThreadStatusDot';
import { show } from '@/routes/threads';
import type { ThreadListRow } from '@/types/shell';

const STATUS_TEXT: Record<ThreadListRow['status'], string> = {
    running: 'Working',
    blocked: 'Blocked',
    paused: 'Paused',
    stopped: 'Stopped',
};

export function ThreadRow({ row, nested = false, active = false }: { row: ThreadListRow; nested?: boolean; active?: boolean }) {
    const [isExpanded, setIsExpanded] = useState(true);
    const hasChildren = row.children.length > 0;

    return (
        <div data-testid={`thread-row-${row.id}`}>
            <div className="flex items-start">
                {hasChildren ? (
                    <button
                        type="button"
                        aria-label={isExpanded ? 'Collapse sub-threads' : 'Expand sub-threads'}
                        aria-expanded={isExpanded}
                        onClick={() => setIsExpanded((value) => !value)}
                        className="mt-2 flex h-5 w-4 shrink-0 items-center justify-center text-faint hover:text-body"
                    >
                        <ChevronRight size={12} className={cn('transition-transform', isExpanded && 'rotate-90')} />
                    </button>
                ) : (
                    <span className="w-4 shrink-0" />
                )}
                <Link
                    href={show.url(row.id)}
                    aria-current={active ? 'page' : undefined}
                    className={cn(
                        'flex min-w-0 flex-1 flex-col gap-0.5 rounded-control px-2 py-1.5 text-muted hover:bg-panel-2',
                        active && 'bg-brand-soft text-body hover:bg-brand-soft',
                    )}
                >
                    <span className="flex items-center gap-2">
                        <ThreadStatusDot row={row} />
                        <span className={cn('min-w-0 flex-1 truncate text-[13px] text-body', nested && 'text-[12.5px]')}>{row.title}</span>
                        {row.isPrivate ? (
                            <>
                                <Lock size={11} className="shrink-0 text-faint" aria-hidden />
                                <Badge tone="neutral">Private</Badge>
                            </>
                        ) : null}
                        {row.isUnattended ? (
                            <Tooltip label="Started by Yak, not a person">
                                <Badge tone="accent">Unattended</Badge>
                            </Tooltip>
                        ) : null}
                        <span className="tnum shrink-0 text-[11px] text-faint">{formatTime(row.lastActivityAt)}</span>
                    </span>
                    <span className="flex items-center gap-2 pl-4 text-[11.5px]">
                        {row.needsYouLabel ? (
                            <span className="truncate text-warn">{row.needsYouLabel}</span>
                        ) : row.repo ? (
                            <span className="truncate font-mono">{row.repo}</span>
                        ) : (
                            <span className="truncate">{STATUS_TEXT[row.status]}</span>
                        )}
                        {hasChildren && !isExpanded ? (
                            <span className="ml-auto flex shrink-0 items-center gap-1" data-testid={`thread-dots-${row.id}`}>
                                {row.children.map((child) => (
                                    <ThreadStatusDot key={child.id} row={child} />
                                ))}
                            </span>
                        ) : null}
                    </span>
                </Link>
            </div>
            {hasChildren && isExpanded ? (
                <div className="ml-2 border-l border-hair pl-1" data-testid={`thread-children-${row.id}`}>
                    {row.children.map((child) => (
                        <ThreadRow key={child.id} row={child} nested />
                    ))}
                </div>
            ) : null}
        </div>
    );
}
