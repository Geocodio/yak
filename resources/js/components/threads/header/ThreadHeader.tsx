import { ConfirmDialog, Menu, cn, toast } from '@geocodio/console-ui';
import { Link, router } from '@inertiajs/react';
import { Copy, GitPullRequest, MoreHorizontal, PanelRight, Share2, Star, Trash2, XCircle } from 'lucide-react';
import { useState } from 'react';
import { useMediaQuery } from '@/lib/useMediaQuery';
import { StatusPill } from '@/components/threads/StatusPill';
import { ThreadHeaderMeta } from '@/components/threads/header/ThreadHeaderMeta';
import { ShareButton } from '@/components/threads/share/ShareButton';
import { ShareThreadDialog } from '@/components/threads/share/ShareThreadDialog';
import { VisibilityBadge } from '@/components/threads/share/VisibilityBadge';
import { refreshThreadList, toggleStar } from '@/lib/threadStar';
import { close, destroy, show } from '@/routes/threads';
import { ThreadDockToggles } from '@/threads/dock/ThreadDock';
import { OpenPullRequestButton, ViewPullRequestButton } from '@/threads/panels/changes/OpenPullRequestButton';
import type { Panel, PanelProps } from '@/threads/panels';
import type { ThreadPageData } from '@/types/threads';

type Props = {
    page: Pick<ThreadPageData, 'thread' | 'canDrive' | 'diffTotals' | 'pullRequest' | 'publishWarning' | 'walkthrough' | 'notice' | 'composer' | 'stackParent'>;
    dockPanels: Panel[];
    activeDock: Panel | null;
    diff: PanelProps['diff'];
    subThreadCount: number;
    isRailOpen: boolean;
    onSelectDock: (id: string | null) => void;
    onToggleRail: () => void;
    onOpenChanges: () => void;
    onOpenOverview: () => void;
};

function copyBranchName(branch: string): void {
    navigator.clipboard.writeText(branch).then(
        () => toast.success('Copied the branch name'),
        () => toast.error('Could not copy the branch name.'),
    );
}

/** The thread page header: title and status, the repo line, the three views, the one Open PR action, and the overflow menu. */
export function ThreadHeader({ page, dockPanels, activeDock, diff, subThreadCount, isRailOpen, onSelectDock, onToggleRail, onOpenChanges, onOpenOverview }: Props) {
    const { thread, canDrive, diffTotals, pullRequest, publishWarning, walkthrough, notice, composer, stackParent } = page;
    const [isSharing, setIsSharing] = useState(false);
    const [confirming, setConfirming] = useState<'close' | 'delete' | null>(null);
    const isDesktop = useMediaQuery('(min-width: 1024px)');
    const showOpenPullRequest = isDesktop && canDrive && thread.status === 'open' && pullRequest === null && diffTotals !== null;

    const isOpen = thread.status === 'open';
    const menuItems = [
        ...(thread.branch !== null ? [{ key: 'copy-branch', label: 'Copy branch name', icon: <Copy size={14} />, onSelect: () => copyBranchName(thread.branch as string), testId: 'thread-menu-copy-branch' }] : []),
        ...(thread.isOwner && !isDesktop ? [{ key: 'share', label: 'Share', icon: <Share2 size={14} />, onSelect: () => setIsSharing(true), testId: 'thread-menu-share' }] : []),
        ...(canDrive && isOpen ? [{ key: 'close', label: 'Close thread', icon: <XCircle size={14} />, dividerAbove: true, onSelect: () => setConfirming('close'), testId: 'thread-menu-close' }] : []),
        ...(canDrive ? [{ key: 'delete', label: 'Delete thread', icon: <Trash2 size={14} />, danger: true, onSelect: () => setConfirming('delete'), testId: 'thread-menu-delete' }] : []),
    ];

    const confirmClose = () => {
        setConfirming(null);
        router.post(close.url(thread.id), {}, { preserveScroll: true, onFinish: refreshThreadList });
    };
    const confirmDelete = () => {
        setConfirming(null);
        router.delete(destroy.url(thread.id), { onFinish: refreshThreadList });
    };

    return (
        <header className="shrink-0 border-b border-hair bg-app px-4 py-2.5 sm:px-5" data-testid="thread-header">
            <div className="flex items-center gap-3">
                <div className="min-w-0 flex-1">
                    <div className="flex min-w-0 items-center gap-2">
                        <h1 className="line-clamp-2 text-[15px] lg:line-clamp-1 font-semibold text-body" data-testid="thread-title">
                            {stackParent !== null && thread.parentThreadId !== null ? (
                                <>
                                    <Link href={show.url(stackParent.id)} className="text-muted hover:underline" data-testid="thread-breadcrumb-parent">{stackParent.title}</Link>
                                    <span className="text-muted"> / </span>
                                </>
                            ) : null}
                            {thread.title}
                        </h1>
                        {thread.parentThreadId === null && (
                            <button
                                type="button"
                                aria-pressed={thread.isStarred}
                                aria-label={thread.isStarred ? 'Remove star' : 'Star thread'}
                                title={thread.isStarred ? 'Remove star' : 'Star thread'}
                                data-testid="thread-star"
                                onClick={() => toggleStar(thread.id)}
                                className="inline-flex size-6 shrink-0 cursor-pointer items-center justify-center rounded-control text-faint hover:bg-panel-2 hover:text-body"
                            >
                                <Star size={14} className={cn(thread.isStarred && 'fill-warn text-warn')} aria-hidden />
                            </button>
                        )}
                        <VisibilityBadge thread={thread} />
                        <StatusPill notice={notice} composerState={composer.state} />
                    </div>
                    <ThreadHeaderMeta thread={thread} diffTotals={diffTotals} subThreadCount={subThreadCount} onOpenChanges={onOpenChanges} onOpenOverview={onOpenOverview} />
                </div>

                <ThreadDockToggles thread={thread} panels={dockPanels} active={activeDock} diff={diff} onSelect={onSelectDock} />

                {isDesktop && pullRequest !== null ? (
                    canDrive && thread.status === 'open' ? (
                        <ViewPullRequestButton thread={thread} pullRequest={pullRequest} isRecording={walkthrough?.isRecording ?? false} />
                    ) : (
                        <a href={pullRequest.url} target="_blank" rel="noreferrer" className="inline-flex h-8 shrink-0 items-center gap-2 rounded-[8px] border border-hair bg-panel px-3 text-[13px] font-medium text-body hover:bg-panel-2" data-testid="thread-header-pr">
                            <GitPullRequest size={13} aria-hidden />
                            #{pullRequest.number}
                            <span className="inline-flex h-[18px] items-center rounded-pill bg-panel-2 px-2 text-[11.5px] text-ok">Open</span>
                        </a>
                    )
                ) : (
                    showOpenPullRequest && (
                        <span className="inline-flex shrink-0" data-testid="thread-header-open-pr">
                            <OpenPullRequestButton thread={thread} publishWarning={publishWarning} isRecording={walkthrough?.isRecording ?? false} />
                        </span>
                    )
                )}

                {isDesktop && (
                    <span className="inline-flex shrink-0">
                        <ShareButton thread={thread} />
                    </span>
                )}

                {menuItems.length > 0 && (
                    <Menu
                        trigger={<MoreHorizontal size={15} aria-hidden />}
                        aria-label="More actions"
                        align="end"
                        items={menuItems}
                        className="size-8 shrink-0 justify-center rounded-[8px] border border-hair bg-panel p-0 text-muted hover:bg-panel-2 hover:text-body"
                        data-testid="thread-menu"
                    />
                )}

                <button
                    type="button"
                    aria-pressed={isRailOpen}
                    aria-label="Overview"
                    title="Overview"
                    data-testid="thread-rail-toggle"
                    onClick={onToggleRail}
                    className="hidden size-8 shrink-0 cursor-pointer items-center justify-center rounded-[8px] border border-hair bg-panel text-muted hover:bg-panel-2 hover:text-body aria-pressed:bg-panel-2 aria-pressed:text-body lg:inline-flex"
                >
                    <PanelRight size={14} aria-hidden />
                </button>
            </div>
            <ConfirmDialog
                open={confirming === 'close'}
                onOpenChange={(open) => !open && setConfirming(null)}
                title="Close this thread?"
                body={`Its sandbox is destroyed${subThreadCount > 0 ? ` and its ${subThreadCount} ${subThreadCount === 1 ? 'sub-thread closes' : 'sub-threads close'} too` : ''}. You can still read it. It cannot be reopened.`}
                confirmLabel="Close thread"
                confirmTestId="thread-close-confirm"
                onConfirm={confirmClose}
            />
            <ConfirmDialog
                open={confirming === 'delete'}
                onOpenChange={(open) => !open && setConfirming(null)}
                title="Delete this thread?"
                body="Messages, files and sub-threads are removed for good. The branch and any pull request on GitHub stay."
                confirmLabel="Delete thread"
                confirmTestId="thread-delete-confirm"
                onConfirm={confirmDelete}
            />
            {isSharing && <ShareThreadDialog thread={thread} onClose={() => setIsSharing(false)} />}
        </header>
    );
}
