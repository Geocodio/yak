import { Link, router } from '@inertiajs/react';
import { Badge, ConfirmDialog, Tooltip, cn } from '@geocodio/console-ui';
import { ChevronRight, Lock, Star, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { formatTime } from '@/components/shell/formatTime';
import { ThreadStatusDot } from '@/components/shell/ThreadStatusDot';
import { refreshThreadList, toggleStar } from '@/lib/threadStar';
import { destroy, show } from '@/routes/threads';
import type { ThreadListRow } from '@/types/shell';

const STATUS_TEXT: Record<ThreadListRow['status'], string> = {
    running: 'Working',
    blocked: 'Blocked',
    paused: 'Paused',
    stopped: 'Stopped',
    closed: 'Closed',
    waiting_for_repository: 'Waiting for repository setup',
};

export function ThreadRow({ row, nested = false, active = false }: { row: ThreadListRow; nested?: boolean; active?: boolean }) {
    const [isExpanded, setIsExpanded] = useState(true);
    const [isConfirmingDelete, setIsConfirmingDelete] = useState(false);
    const hasChildren = row.children.length > 0;
    const isClosed = row.status === 'closed';
    const hoverControl = 'absolute top-1.5 hidden size-5 items-center justify-center rounded-control text-faint hover:bg-panel hover:text-body focus-visible:flex group-hover/row:flex';

    return (
        <div data-testid={`thread-row-${row.id}`}>
            <div className="group/row relative flex items-start">
                {nested ? <span className="w-4 shrink-0" /> : null}
                <Link
                    href={show.url(row.id)}
                    aria-current={active ? 'page' : undefined}
                    className={cn(
                        'flex min-w-0 flex-1 flex-col gap-0.5 rounded-control px-2 py-1.5 text-muted hover:bg-panel-2',
                        active && 'bg-brand-soft text-body hover:bg-brand-soft',
                        isClosed && 'opacity-70',
                    )}
                >
                    <span className="flex items-center gap-2">
                        <ThreadStatusDot row={row} />
                        <span className={cn('flex min-w-0 flex-1 items-center gap-1 text-[13px] text-body', nested && 'text-[12.5px]')} data-testid={`thread-title-${row.id}`}>
                            {row.isPrivate ? <Lock size={11} className="shrink-0 text-faint" aria-hidden /> : null}
                            <span className="truncate">{row.title}</span>
                        </span>
                        {row.isUnattended ? (
                            <Tooltip label="Started by Yak, not a person">
                                <Badge tone="accent">Unattended</Badge>
                            </Tooltip>
                        ) : null}
                        {row.ownerInitials ? (
                            <span data-testid={`thread-owner-${row.id}`} className="shrink-0 rounded-pill bg-panel-2 px-1.5 text-[10px] text-muted">
                                {row.ownerInitials}
                            </span>
                        ) : null}
                        {row.isStarred ? <Star size={11} className="shrink-0 fill-warn text-warn group-hover/row:hidden" aria-hidden data-testid={`thread-starred-${row.id}`} /> : null}
                        <span className="tnum shrink-0 text-[11px] text-faint group-hover/row:invisible">{formatTime(row.lastActivityAt)}</span>
                    </span>
                    <span className="flex items-center gap-2 pl-4 text-[11.5px]">
                        {row.needsYouLabel ? (
                            <span className={cn('truncate', row.needsYouReason === 'blocked' ? 'text-fail' : 'text-brand')}>{row.needsYouLabel}</span>
                        ) : row.status === 'waiting_for_repository' ? (
                            <span className="truncate text-muted" data-testid={`thread-waiting-repository-${row.id}`}>{STATUS_TEXT[row.status]}</span>
                        ) : row.repo ? (
                            <span className="truncate font-mono">{row.repo}</span>
                        ) : (
                            <span className="truncate">{STATUS_TEXT[row.status]}</span>
                        )}
                        {row.isPrivate ? <Badge tone="neutral" data-testid={`thread-visibility-${row.id}`}>{row.isShared ? 'Shared' : 'Private'}</Badge> : null}
                        {hasChildren && !isExpanded ? (
                            <span className="ml-auto flex shrink-0 items-center gap-1" data-testid={`thread-dots-${row.id}`}>
                                {row.children.map((child) => (
                                    <ThreadStatusDot key={child.id} row={child} />
                                ))}
                            </span>
                        ) : null}
                    </span>
                </Link>
                {nested ? null : (
                    <>
                        {isClosed && row.canDelete ? (
                            <button type="button" aria-label="Delete thread" title="Delete thread" data-testid={`thread-delete-${row.id}`} onClick={() => setIsConfirmingDelete(true)} className={cn(hoverControl, hasChildren ? 'right-[3.75rem]' : 'right-7')}>
                                <Trash2 size={12} aria-hidden />
                            </button>
                        ) : null}
                        <button type="button" aria-label={row.isStarred ? 'Remove star' : 'Star thread'} aria-pressed={row.isStarred} title={row.isStarred ? 'Remove star' : 'Star thread'} data-testid={`thread-star-${row.id}`} onClick={() => toggleStar(row.id)} className={cn(hoverControl, hasChildren ? 'right-9' : 'right-1.5')}>
                            <Star size={12} className={cn(row.isStarred && 'fill-warn text-warn')} aria-hidden />
                        </button>
                    </>
                )}
                {hasChildren ? (
                    <button
                        type="button"
                        aria-label={isExpanded ? 'Collapse sub-threads' : 'Expand sub-threads'}
                        aria-expanded={isExpanded}
                        data-testid={`thread-toggle-${row.id}`}
                        onClick={() => setIsExpanded((value) => !value)}
                        className="mt-2 mr-1 flex h-5 shrink-0 items-center gap-0.5 rounded-pill bg-panel-2 px-1.5 text-[11px] text-muted hover:text-body"
                    >
                        {row.children.length}
                        <ChevronRight size={10} className={cn('transition-transform', isExpanded && 'rotate-90')} />
                    </button>
                ) : null}
            </div>
            <ConfirmDialog
                open={isConfirmingDelete}
                onOpenChange={setIsConfirmingDelete}
                title="Delete this thread?"
                body="Messages, files and sub-threads are removed for good. The branch and any pull request on GitHub stay."
                confirmLabel="Delete thread"
                confirmTestId={`thread-delete-confirm-${row.id}`}
                onConfirm={() => {
                    setIsConfirmingDelete(false);
                    router.delete(destroy.url(row.id), { preserveScroll: true, onFinish: refreshThreadList });
                }}
            />
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
