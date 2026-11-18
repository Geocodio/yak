import { ConfirmDialog, Menu, cn, toast } from '@geocodio/console-ui';
import { Link, router } from '@inertiajs/react';
import { Ban, Copy, GitPullRequest, Link2, MessageSquareCode, MoreHorizontal, MoveRight, PanelRight, Share2, Star, Trash2, XCircle } from 'lucide-react';
import { useMemo, useState } from 'react';
import { THREAD_GROUP, headerActions } from '@/commandPalette/threadPageActions';
import { useCommandPaletteActions } from '@/commandPalette/useCommandPaletteActions';
import { useMediaQuery } from '@/lib/useMediaQuery';
import { StatusPill } from '@/components/threads/StatusPill';
import { SourceLink } from '@/components/threads/header/SourceLink';
import { threadStatus } from '@/threads/threadStatus';
import { phoneStatusPill, type PhonePillTone } from '@/threads/phoneHeader';
import { ThreadHeaderMeta } from '@/components/threads/header/ThreadHeaderMeta';
import { ShareButton } from '@/components/threads/share/ShareButton';
import { ShareThreadDialog } from '@/components/threads/share/ShareThreadDialog';
import { VisibilityBadge } from '@/components/threads/share/VisibilityBadge';
import { breadcrumbParent } from '@/components/threads/header/breadcrumbParent';
import { toggleStar } from '@/lib/threadStar';
import { DELETE_THREAD_CONFIRM, closeThread, closeThreadConfirm, deleteThread } from '@/lib/threadLifecycle';
import { cancel, requestReview, show } from '@/routes/threads';
import { ThreadDockToggles } from '@/threads/dock/ThreadDock';
import { HeldPullRequestButton, OpenPullRequestButton, ViewPullRequestButton } from '@/threads/panels/changes/OpenPullRequestButton';
import type { Panel, PanelProps } from '@/threads/panels';
import type { ThreadPageData } from '@/types/threads';

type Props = {
    page: Pick<ThreadPageData, 'thread' | 'canDrive' | 'diffTotals' | 'pullRequest' | 'publishWarning' | 'walkthrough' | 'notice' | 'composer' | 'stackParent' | 'request' | 'actions' | 'coOwner' | 'stopped'>;
    dockPanels: Panel[];
    activeDock: Panel | null;
    diff: PanelProps['diff'];
    subThreadCount: number;
    isRailOpen: boolean;
    onSelectDock: (id: string | null) => void;
    onToggleRail: () => void;
    onOpenChanges: () => void;
    onOpenOverview: () => void;
    /** Opens the move-to-another-repository dialog. */
    onMove: () => void;
    /** The label of the progress step that failed, if any; it gives the phone's stopped pill its reason. */
    failedStepLabel?: string | null;
    /** A thread made from an old task shows as closed on a phone. */
    isLegacy?: boolean;
};

const PILL_TONES: Record<PhonePillTone, string> = {
    ok: 'bg-ok-soft text-ok',
    warn: 'bg-warn-soft text-warn',
    fail: 'bg-fail-soft text-fail',
    info: 'bg-info-soft text-info',
    brand: 'bg-brand-soft text-brand',
    neutral: 'bg-panel-2 text-muted',
};

function copyBranchName(branch: string): void {
    navigator.clipboard.writeText(branch).then(
        () => toast.success('Copied the branch name'),
        () => toast.error('Could not copy the branch name.'),
    );
}

function copyThreadLink(threadId: string): void {
    navigator.clipboard.writeText(new URL(show.url(threadId), window.location.origin).toString()).then(
        () => toast.success('Copied the thread link'),
        () => toast.error('Could not copy the thread link.'),
    );
}

/** The thread page header: title and status, the repo line, the three views, the one Open PR action, and the overflow menu. */
export function ThreadHeader({ page, dockPanels, activeDock, diff, subThreadCount, isRailOpen, onSelectDock, onToggleRail, onOpenChanges, onOpenOverview, onMove, failedStepLabel = null, isLegacy = false }: Props) {
    const { thread, canDrive, diffTotals, pullRequest, publishWarning, walkthrough, notice, composer, stackParent, request, actions: threadActions, coOwner, stopped } = page;
    const [isSharing, setIsSharing] = useState(false);
    const [confirming, setConfirming] = useState<'close' | 'delete' | 'cancel' | 'requestReview' | null>(null);
    const [isBusy, setIsBusy] = useState(false);
    const isDesktop = useMediaQuery('(min-width: 1024px)');
    const isOpen = thread.status === 'open';
    const canStar = thread.parentThreadId === null;
    // A thread on the person's computer cannot push or open a pull request from Yak.
    const canOpenPullRequest = canDrive && isOpen && pullRequest === null && diffTotals !== null && composer.state !== 'disabled_computer';
    const showOpenPullRequest = isDesktop && canOpenPullRequest;
    const isHeldForCoOwner = pullRequest === null && (coOwner?.status === 'pending' || thread.pullRequestOpening?.state === 'held');
    const actions = useMemo(
        () =>
            headerActions({
                threadId: thread.id,
                isOwner: thread.canShare,
                visibility: thread.visibility,
                canOpenPullRequest,
                pullRequestOpeningState: thread.pullRequestOpening?.state,
                publishWarning,
                openShareDialog: () => setIsSharing(true),
                canStar,
                isStarred: thread.isStarred,
                canClose: canDrive && isOpen,
                canDelete: canDrive,
                subThreadCount,
            }),
        [thread.id, thread.canShare, thread.visibility, canOpenPullRequest, thread.pullRequestOpening?.state, publishWarning, canStar, thread.isStarred, canDrive, isOpen, subThreadCount],
    );
    const parent = breadcrumbParent(thread, stackParent);
    const phonePill = phoneStatusPill({
        noticeStatus: threadStatus(notice, 'idle'),
        isClosed: !isOpen,
        isWaitingForRepository: !thread.hasRepository,
        isLegacy,
        isHeldForCoOwner,
        isWorking: composer.state === 'working',
        pullRequest,
        isStopped: stopped !== null && stopped.kind === 'yak_stopped',
        failedStepLabel,
    });
    const hasChannelSource = request.source !== undefined && ['slack', 'linear', 'sentry', 'github', 'email'].includes(request.source.key);
    const phoneRepo = thread.repo === 'unknown' ? null : thread.repo;
    useCommandPaletteActions(THREAD_GROUP, actions);

    const closeConfirm = closeThreadConfirm(subThreadCount);
    const menuItems = [
        { key: 'copy-link', label: 'Copy thread link', icon: <Link2 size={14} />, onSelect: () => copyThreadLink(thread.id), testId: 'thread-menu-copy-link' },
        ...(thread.branch !== null ? [{ key: 'copy-branch', label: 'Copy branch name', icon: <Copy size={14} />, onSelect: () => copyBranchName(thread.branch as string), testId: 'thread-menu-copy-branch' }] : []),
        ...(thread.canShare && !isDesktop ? [{ key: 'share', label: 'Share', icon: <Share2 size={14} />, onSelect: () => setIsSharing(true), testId: 'thread-menu-share' }] : []),
        ...(threadActions.canRequestReview ? [{ key: 'request-review', label: 'Request review', icon: <MessageSquareCode size={14} />, dividerAbove: true, onSelect: () => setConfirming('requestReview'), testId: 'thread-menu-request-review' }] : []),
        ...(threadActions.canMove ? [{ key: 'move', label: 'Move to another repository', icon: <MoveRight size={14} />, onSelect: onMove, testId: 'thread-menu-move' }] : []),
        ...(threadActions.canCancel ? [{ key: 'cancel', label: 'Cancel', icon: <Ban size={14} />, danger: true, onSelect: () => setConfirming('cancel'), testId: 'thread-menu-cancel' }] : []),
        ...(canDrive && isOpen ? [{ key: 'close', label: 'Close thread', icon: <XCircle size={14} />, dividerAbove: true, onSelect: () => setConfirming('close'), testId: 'thread-menu-close' }] : []),
        ...(canDrive ? [{ key: 'delete', label: 'Delete thread', icon: <Trash2 size={14} />, danger: true, onSelect: () => setConfirming('delete'), testId: 'thread-menu-delete' }] : []),
    ];

    const confirmClose = () => {
        setConfirming(null);
        closeThread(thread.id);
    };
    const runConfirmed = (url: string) => {
        setIsBusy(true);
        router.post(url, {}, { preserveScroll: true, onFinish: () => { setIsBusy(false); setConfirming(null); } });
    };
    const confirmDelete = () => {
        setConfirming(null);
        deleteThread(thread.id);
    };

    return (
        <header className="shrink-0 border-b border-hair bg-app px-4 py-2.5 sm:px-5" data-testid="thread-header">
            <div className="flex items-center gap-3">
                <div className="min-w-0 flex-1">
                    <div className="flex min-w-0 items-center gap-2">
                        <h1 className="text-[15px] font-semibold max-sm:min-w-0 max-sm:truncate sm:line-clamp-2 lg:line-clamp-1 text-body" data-testid="thread-title">
                            {parent !== null ? (
                                <>
                                    <Link href={show.url(parent.id)} className="text-muted hover:underline" data-testid="thread-breadcrumb-parent">{parent.title}</Link>
                                    <span className="text-muted"> / </span>
                                </>
                            ) : null}
                            {thread.title}
                        </h1>
                        {canStar && (
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
                        <span className="contents max-sm:hidden">
                            <StatusPill notice={notice} composerState={composer.state} />
                        </span>
                    </div>
                    <div className="mt-0.5 flex min-w-0 items-center gap-2 overflow-hidden whitespace-nowrap text-[12px] text-muted sm:hidden" data-testid="thread-header-phone-meta">
                        {hasChannelSource && request.source !== undefined ? (
                            <span className="min-w-0 truncate" data-testid="thread-phone-source"><SourceLink source={request.source} testId="thread-phone-source-link" /></span>
                        ) : (
                            phoneRepo !== null && <span className="min-w-0 truncate">{phoneRepo}</span>
                        )}
                        {phonePill !== null && <span className={cn('inline-flex shrink-0 items-center rounded-pill px-2 py-0.5 text-[11.5px] font-medium', PILL_TONES[phonePill.tone])} data-testid="thread-phone-pill">{phonePill.label}</span>}
                        {thread.branch !== null && (
                            <span className="@container min-w-0 flex-1 overflow-hidden" data-testid="thread-phone-branch">
                                <code className="hidden truncate font-mono text-[11.5px] @min-[4.5rem]:block">{thread.branch}</code>
                            </span>
                        )}
                    </div>
                    <div className="max-sm:hidden">
                        <ThreadHeaderMeta thread={thread} diffTotals={diffTotals} subThreadCount={subThreadCount} source={request.source} onOpenChanges={onOpenChanges} onOpenOverview={onOpenOverview} />
                    </div>
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
                ) : isDesktop && isHeldForCoOwner ? (
                    <HeldPullRequestButton />
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
                title={closeConfirm.title}
                body={closeConfirm.body}
                confirmLabel={closeConfirm.confirmLabel}
                confirmTestId="thread-close-confirm"
                onConfirm={confirmClose}
            />
            <ConfirmDialog
                open={confirming === 'delete'}
                onOpenChange={(open) => !open && setConfirming(null)}
                title={DELETE_THREAD_CONFIRM.title}
                body={DELETE_THREAD_CONFIRM.body}
                confirmLabel={DELETE_THREAD_CONFIRM.confirmLabel}
                confirmTestId="thread-delete-confirm"
                onConfirm={confirmDelete}
            />
            <ConfirmDialog
                open={confirming === 'cancel'}
                onOpenChange={(open) => !open && setConfirming(null)}
                title="Cancel this task?"
                body="The sandbox will be destroyed and the agent will stop."
                confirmLabel="Cancel task"
                busy={isBusy}
                confirmTestId="thread-cancel-confirm"
                onConfirm={() => runConfirmed(cancel.url(thread.id))}
            />
            <ConfirmDialog
                open={confirming === 'requestReview'}
                onOpenChange={(open) => !open && setConfirming(null)}
                title="Request a review of this PR?"
                body="Yak starts a new review task for the latest commit on this PR."
                confirmLabel="Confirm"
                destructive={false}
                busy={isBusy}
                confirmTestId="thread-request-review-confirm"
                onConfirm={() => runConfirmed(requestReview.url(thread.id))}
            />
            {isSharing && <ShareThreadDialog thread={thread} onClose={() => setIsSharing(false)} />}
        </header>
    );
}
