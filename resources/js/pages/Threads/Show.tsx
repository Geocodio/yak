import { Head } from '@inertiajs/react';
import { cn } from '@geocodio/console-ui';
import { useRef, useState, type ReactNode } from 'react';
import { ThreadComposer } from '@/components/threads/composer/ThreadComposer';
import { FailedStepStrip } from '@/components/threads/conversation/FailedStepStrip';
import { Conversation } from '@/components/threads/conversation/Conversation';
import { ChildBriefCard } from '@/components/threads/header/ChildBriefCard';
import { ThreadHeader } from '@/components/threads/header/ThreadHeader';
import { PrivateThreadBanner } from '@/components/threads/share/PrivateThreadBanner';
import { ChildParentBanner } from '@/components/threads/stack/ChildParentBanner';
import { TeleportConversationNotices } from '@/components/threads/teleport/TeleportConversationNotices';
import { TeleportToComputerDialog } from '@/components/threads/teleport/TeleportToComputerDialog';
import { TeleportToYakDialog } from '@/components/threads/teleport/TeleportToYakDialog';
import { AppLayout } from '@/layouts/AppLayout';
import { useMediaQuery } from '@/lib/useMediaQuery';
import { ClaudeWorkingStrip } from '@/threads/dock/ClaudeWorkingStrip';
import { phoneDockIdFor, phoneTabIdFor, type PhoneTabId } from '@/threads/dock/dockState';
import { ThreadDockPanel } from '@/threads/dock/ThreadDock';
import { ThreadPhoneTabs } from '@/threads/dock/ThreadPhoneTabs';
import { useDockState } from '@/threads/dock/useDockState';
import { dockPanelsFor, type PanelProps } from '@/threads/panels';
import '@/threads/panels/index';
import { Overview } from '@/threads/panels/environment/Overview';
import { resolveFilesMode, type FilesMode, type FocusRequest } from '@/threads/panels/files/filesMode';
import { AddChildDialog } from '@/threads/panels/stack/AddChildDialog';
import { useStack } from '@/threads/panels/stack/useStack';
import { useThreadDiff } from '@/threads/panels/changes/useThreadDiff';
import type { LinkState } from '@/threads/realtime/catchUpRunner';
import type { PageProps } from '@/types/shared';
import type { ThreadPageData } from '@/types/threads';

type Props = PageProps<ThreadPageData>;

/** Below this width the rail floats over the open view instead of sitting beside it. */
const WIDE_RAIL_QUERY = '(min-width: 1200px)';

/** From this width the page shows the views side by side; below it the phone tabs take over. */
const DESKTOP_QUERY = '(min-width: 1024px)';

export default function Show({ thread, canDrive, diffTotals, pullRequest, publishWarning, walkthrough, sharedFiles, brief, messages, localTurns, notice, composer, capacity, teleport, stackParent, modelLabels }: Props) {
    const dockPanels = dockPanelsFor(thread);
    const dock = useDockState();
    const dockPanelIds = dockPanels.map((panel) => panel.id);
    const activeDock = dockPanels.find((panel) => panel.id === dock.activeId) ?? null;
    const isWide = useMediaQuery(WIDE_RAIL_QUERY);
    const isDesktop = useMediaQuery(DESKTOP_QUERY);
    const [isRailOpen, setIsRailOpen] = useState(() => activeDock === null || (activeDock.id !== 'files' && isWide));
    const [phoneTab, setPhoneTab] = useState<PhoneTabId>(() => phoneTabIdFor(activeDock?.id ?? null));
    const [filesMode, setFilesMode] = useState<FilesMode | null>(null);
    const [focusRequest, setFocusRequest] = useState<FocusRequest | null>(null);
    const focusCounter = useRef(0);
    const [linkKind, setLinkKind] = useState<LinkState['kind']>('ok');
    const [restoredDraft, setRestoredDraft] = useState<{ text: string } | null>(null);
    const [isAddingSubThread, setIsAddingSubThread] = useState(false);
    const [isTeleportOpen, setIsTeleportOpen] = useState(false);
    const { stack, reload: reloadStack } = useStack(thread);
    const { diff, isLoading: isDiffLoading } = useThreadDiff(thread);
    const canAddSubThread = canDrive && stack !== null && stack.canDrive && stack.isRoot && thread.ownerSide === 'yak' && thread.status === 'open';
    const subThreadCount = stack?.children.length ?? 0;
    const isOverlayRail = !isWide && activeDock !== null;
    const isRailHidden = isDesktop ? !isRailOpen : phoneTab !== 'overview';

    /** Opens a view and decides the rail: Files in Changed mode takes the full width, a narrow screen floats the rail, otherwise it stays beside the view. */
    const selectDock = (id: string | null, requestedMode: FilesMode | null = filesMode) => {
        dock.setActiveId(id);
        const isChangedReview = id === 'files' && resolveFilesMode(requestedMode, diff, isDiffLoading) !== 'everything';
        if (isChangedReview || (id !== null && !isWide)) {
            setIsRailOpen(false);
        } else if (isWide) {
            setIsRailOpen(true);
        }
    };
    const requestFile = (path: string | undefined) => {
        focusCounter.current += 1;
        setFocusRequest(path === undefined ? null : { path, id: focusCounter.current });
    };
    const openChanges = (path?: string) => {
        setFilesMode('changed');
        requestFile(path);
        selectDock('files', 'changed');
        setPhoneTab('files');
    };
    const openFile = (path: string) => {
        setFilesMode('everything');
        requestFile(path);
        selectDock('files', 'everything');
        setPhoneTab('files');
    };
    const changeFilesMode = (mode: FilesMode) => {
        setFilesMode(mode);
        setFocusRequest(null);
    };
    const openOverview = () => {
        setIsRailOpen(true);
        setPhoneTab('overview');
    };
    const selectPhoneTab = (id: PhoneTabId) => {
        setPhoneTab(id);
        const dockId = phoneDockIdFor(id);
        if (dockId !== null) {
            dock.setActiveId(dockId);
        }
    };
    const isPhoneDockVisible = phoneTab === 'preview' || phoneTab === 'files' || phoneTab === 'terminal';
    const changesCount = diff?.filesCount ?? 0;

    const panelProps: PanelProps = {
        thread,
        canDrive,
        notice,
        sharedFiles,
        composerState: composer.state,
        composer,
        modelLabels,
        capacity,
        accountName: composer.accountName,
        environmentSwitch: teleport.environmentSwitch,
        stack,
        reloadStack,
        diff,
        isDiffLoading,
        filesMode,
        onFilesModeChange: changeFilesMode,
        focusRequest,
        onOpenChanges: openChanges,
        onOpenFile: openFile,
        onSelectView: (id) => selectDock(id),
        onShowConversation: () => {
            selectDock(null);
            setPhoneTab('chat');
        },
        onCloseRail: isOverlayRail ? () => setIsRailOpen(false) : undefined,
        onAddSubThread: canAddSubThread ? () => setIsAddingSubThread(true) : undefined,
        onTeleport: () => setIsTeleportOpen(true),
    };

    return (
        <>
            <Head title={thread.title} />

            <ThreadHeader
                page={{ thread, canDrive, diffTotals, pullRequest, publishWarning, walkthrough, notice, composer, stackParent }}
                dockPanels={dockPanels}
                activeDock={activeDock}
                diff={diff}
                subThreadCount={subThreadCount}
                isRailOpen={isRailOpen}
                onSelectDock={(id) => selectDock(id)}
                onToggleRail={() => setIsRailOpen((value) => !value)}
                onOpenChanges={() => openChanges()}
                onOpenOverview={openOverview}
            />
            <FailedStepStrip notice={notice} threadId={thread.id} readonly={composer.state === 'readonly'} />
            <PrivateThreadBanner thread={thread} />
            <ChildParentBanner thread={thread} parent={stackParent} hasBrief={brief !== null} />
            <ThreadPhoneTabs registeredDockIds={dockPanelIds} active={phoneTab} isWorking={thread.runnerState === 'busy'} changesCount={changesCount} onSelect={selectPhoneTab} />
            {isPhoneDockVisible && <ClaudeWorkingStrip thread={thread} onOpenChat={() => setPhoneTab('chat')} />}

            <div className="relative flex min-h-0 flex-1 lg:overflow-hidden">
                <div
                    className={cn('min-w-0 flex-1 flex-col bg-app lg:flex', phoneTab === 'chat' ? 'flex' : 'hidden', activeDock !== null && 'lg:w-[380px] lg:flex-none')}
                    data-testid="thread-conversation"
                >
                    {brief !== null && stackParent !== null && <ChildBriefCard brief={brief} parentTitle={stackParent.title} />}
                    <TeleportConversationNotices thread={thread} teleport={teleport} />
                    <Conversation modelLabels={modelLabels} threadId={thread.id} title={thread.title} repo={thread.repo} branch={thread.branch} messages={messages} walkthrough={walkthrough} sharedFiles={sharedFiles} isTeamThread={thread.visibility === 'team'} canShareFiles={canDrive} localTurns={localTurns} notice={notice} readonly={composer.state === 'readonly'} environmentNote={teleport.environmentNote === null || teleport.environmentSwitch === null ? null : { text: teleport.environmentNote, ...teleport.environmentSwitch }} environmentSwitch={teleport.environmentSwitch} pullRequestCount={thread.pullRequestCount} lastActivityAt={thread.lastActivityAt} onLinkChange={setLinkKind} onEditUnsent={(text) => setRestoredDraft({ text })} onOpenChanges={openChanges} />
                    <ThreadComposer thread={thread} composer={composer} notice={notice} teleportState={teleport.state} isOffline={linkKind === 'offline'} restoredDraft={restoredDraft} onAddSubThread={canAddSubThread ? () => setIsAddingSubThread(true) : undefined} />
                </div>

                <ThreadDockPanel active={activeDock} isPhoneVisible={isPhoneDockVisible} panelProps={panelProps} />

                <aside
                    aria-label="Overview"
                    aria-hidden={isRailHidden || undefined}
                    inert={isRailHidden}
                    className={cn(
                        'w-full shrink-0 flex-col bg-app',
                        phoneTab === 'overview' ? 'flex' : 'hidden',
                        'lg:flex lg:overflow-hidden lg:transition-[width,opacity,transform,box-shadow] lg:duration-200 lg:ease-out lg:motion-reduce:transition-none',
                        isOverlayRail
                            ? 'lg:absolute lg:inset-y-0 lg:right-0 lg:z-20 lg:w-[312px]'
                            : isRailOpen
                              ? 'lg:w-[312px]'
                              : 'lg:w-0',
                        isOverlayRail && isRailOpen && 'lg:shadow-[-14px_0_30px_-18px_rgb(0_0_0/0.35)]',
                        isRailOpen ? 'lg:translate-x-0 lg:opacity-100' : isOverlayRail ? 'lg:translate-x-full lg:opacity-0' : 'lg:opacity-0',
                    )}
                    data-testid="thread-sidebar"
                    data-open={isRailOpen}
                >
                    <div className="flex min-h-0 w-full flex-1 flex-col overflow-y-auto lg:w-[312px] lg:shrink-0 lg:border-l lg:border-hair">
                        <Overview {...panelProps} />
                    </div>
                </aside>
            </div>

            {isAddingSubThread && stack !== null && (
                <AddChildDialog threadId={thread.id} parentTitle={thread.title} stack={{ models: stack.models, parentAccountName: stack.parentAccountName, maxChildren: stack.maxChildren, awake: stack.limit.awake, limit: stack.limit.current, childCount: stack.children.length }} onClose={() => setIsAddingSubThread(false)} onAdded={() => { reloadStack(); openOverview(); }} />
            )}
            {teleport.state === 'on_device' ? (
                <TeleportToYakDialog thread={thread} teleport={teleport} open={isTeleportOpen} onClose={() => setIsTeleportOpen(false)} />
            ) : (
                <TeleportToComputerDialog thread={thread} teleport={teleport} open={isTeleportOpen} onClose={() => setIsTeleportOpen(false)} />
            )}
        </>
    );
}

Show.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
