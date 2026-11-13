import { Menu, toast } from '@geocodio/console-ui';
import { Link } from '@inertiajs/react';
import { Copy, GitPullRequest, Layers, Monitor, MoreHorizontal, PanelRight, Share2 } from 'lucide-react';
import { useState } from 'react';
import { useMediaQuery } from '@/lib/useMediaQuery';
import { StatusPill } from '@/components/threads/StatusPill';
import { ThreadHeaderMeta } from '@/components/threads/header/ThreadHeaderMeta';
import { ShareButton } from '@/components/threads/share/ShareButton';
import { ShareThreadDialog } from '@/components/threads/share/ShareThreadDialog';
import { VisibilityBadge } from '@/components/threads/share/VisibilityBadge';
import { show } from '@/routes/threads';
import { ThreadDockToggles } from '@/threads/dock/ThreadDock';
import { OpenPullRequestButton } from '@/threads/panels/changes/OpenPullRequestButton';
import type { Panel, PanelProps } from '@/threads/panels';
import type { ThreadPageData } from '@/types/threads';

type Props = {
    page: Pick<ThreadPageData, 'thread' | 'canDrive' | 'diffTotals' | 'pullRequest' | 'publishWarning' | 'notice' | 'composer' | 'stackParent' | 'teleport'>;
    dockPanels: Panel[];
    activeDock: Panel | null;
    diff: PanelProps['diff'];
    subThreadCount: number;
    canAddSubThread: boolean;
    isRailOpen: boolean;
    onSelectDock: (id: string | null) => void;
    onToggleRail: () => void;
    onOpenOverview: () => void;
    onAddSubThread: () => void;
    onTeleport: () => void;
};

function copyBranchName(branch: string): void {
    navigator.clipboard.writeText(branch).then(
        () => toast.success('Copied the branch name'),
        () => toast.error('Could not copy the branch name.'),
    );
}

/** The thread page header: title and status, the repo line, the four views, the one Open PR action, and the overflow menu. */
export function ThreadHeader({ page, dockPanels, activeDock, diff, subThreadCount, canAddSubThread, isRailOpen, onSelectDock, onToggleRail, onOpenOverview, onAddSubThread, onTeleport }: Props) {
    const { thread, canDrive, diffTotals, pullRequest, publishWarning, notice, composer, stackParent, teleport } = page;
    const [isSharing, setIsSharing] = useState(false);
    const isDesktop = useMediaQuery('(min-width: 1024px)');
    const canTeleport = teleport.canTeleport && teleport.state !== 'teleporting';
    const showOpenPullRequest = isDesktop && canDrive && thread.status === 'open' && pullRequest === null && diffTotals !== null;

    const menuItems = [
        ...(canAddSubThread ? [{ key: 'sub-thread', label: 'Add a sub-thread', icon: <Layers size={14} />, onSelect: onAddSubThread, testId: 'thread-menu-add-sub-thread' }] : []),
        ...(canTeleport ? [{ key: 'teleport', label: teleport.state === 'on_device' ? 'Teleport to Yak' : 'Teleport to your computer', icon: <Monitor size={14} />, onSelect: onTeleport, testId: 'thread-menu-teleport' }] : []),
        ...(thread.branch !== null ? [{ key: 'copy-branch', label: 'Copy branch name', icon: <Copy size={14} />, dividerAbove: true, onSelect: () => copyBranchName(thread.branch as string), testId: 'thread-menu-copy-branch' }] : []),
        ...(thread.isOwner ? [{ key: 'share', label: 'Share', icon: <Share2 size={14} />, onSelect: () => setIsSharing(true), testId: 'thread-menu-share' }] : []),
    ];

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
                        <VisibilityBadge thread={thread} />
                        <StatusPill notice={notice} composerState={composer.state} />
                    </div>
                    <ThreadHeaderMeta thread={thread} diffTotals={diffTotals} subThreadCount={subThreadCount} onOpenChanges={() => onSelectDock('changes')} onOpenOverview={onOpenOverview} />
                </div>

                <ThreadDockToggles thread={thread} panels={dockPanels} active={activeDock} diff={diff} onSelect={onSelectDock} />

                {isDesktop && pullRequest !== null ? (
                    <a href={pullRequest.url} target="_blank" rel="noreferrer" className="inline-flex h-8 shrink-0 items-center gap-2 rounded-[8px] border border-hair bg-panel px-3 text-[13px] font-medium text-body hover:bg-panel-2" data-testid="thread-header-pr">
                        <GitPullRequest size={13} aria-hidden />
                        #{pullRequest.number}
                        <span className="inline-flex h-[18px] items-center rounded-pill bg-panel-2 px-2 text-[11.5px] text-ok">Open</span>
                    </a>
                ) : (
                    showOpenPullRequest && (
                        <span className="inline-flex shrink-0" data-testid="thread-header-open-pr">
                            <OpenPullRequestButton thread={thread} publishWarning={publishWarning} />
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
            {isSharing && <ShareThreadDialog thread={thread} onClose={() => setIsSharing(false)} />}
        </header>
    );
}
