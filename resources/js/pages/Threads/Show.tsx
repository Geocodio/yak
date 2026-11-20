import { usePullRequestToasts } from '@/threads/usePullRequestToasts';
import { Head } from '@inertiajs/react';
import { cn } from '@geocodio/console-ui';
import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { failedStepReason, isProgressShownOnPhone } from '@/threads/phoneHeader';
import { clearCardDrafts } from '@/components/threads/cards/useCardDraft';
import { THREAD_GROUP, showActions, type ShowActionOptions } from '@/commandPalette/threadPageActions';
import { threadDataActions } from '@/commandPalette/threadDataActions';
import { useCommandPaletteActions } from '@/commandPalette/useCommandPaletteActions';
import { ThreadCards } from '@/components/threads/cards/ThreadCards';
import { ThreadComposer } from '@/components/threads/composer/ThreadComposer';
import { withoutQueuedMessages } from '@/components/threads/composer/queuedMessages';
import { FailedStepStrip } from '@/components/threads/conversation/FailedStepStrip';
import { RunLogOverlay, runLogMeta, type RunLogTarget } from '@/components/threads/conversation/RunLogOverlay';
import { LegacyConversation } from '@/components/threads/conversation/LegacyConversation';
import { Conversation } from '@/components/threads/conversation/Conversation';
import { ChildBriefCard } from '@/components/threads/header/ChildBriefCard';
import { breadcrumbParent } from '@/components/threads/header/breadcrumbParent';
import { MoveRepositoryDialog } from '@/components/threads/header/MoveRepositoryDialog';
import { ProgressRow } from '@/components/threads/header/ProgressRow';
import { ThreadHeader } from '@/components/threads/header/ThreadHeader';
import { PrivateThreadBanner } from '@/components/threads/share/PrivateThreadBanner';
import { ChildParentBanner } from '@/components/threads/stack/ChildParentBanner';
import { canTeleportThread, teleportLabel as teleportLabelFor } from '@/components/threads/teleport/canTeleportThread';
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
import { AgentsProvider } from '@/threads/panels/agents/AgentsContext';
import { agentTitle } from '@/threads/panels/agents/agentsState';
import { log as agentLog } from '@/routes/threads/agents';
import type { ThreadAgent } from '@/types/thread-agents';
import '@/threads/panels/index';
import { Overview } from '@/threads/panels/environment/Overview';
import { resolveFilesMode, type FilesMode, type FocusRequest } from '@/threads/panels/files/filesMode';
import { AddChildDialog } from '@/threads/panels/stack/AddChildDialog';
import { useStack } from '@/threads/panels/stack/useStack';
import { useThreadDiff } from '@/threads/panels/changes/useThreadDiff';
import type { LinkState } from '@/threads/realtime/catchUpRunner';
import { useFragmentScroll } from '@/components/threads/useFragmentScroll';
import type { PageProps } from '@/types/shared';
import type { ThreadPageData } from '@/types/threads';

type Props = PageProps<ThreadPageData>;

/** Below this width the rail floats over the open view instead of sitting beside it. */
const WIDE_RAIL_QUERY = '(min-width: 1200px)';

/** From this width the page shows the views side by side; below it the phone tabs take over. */
const DESKTOP_QUERY = '(min-width: 1024px)';

export default function Show({ thread, canDrive, diffTotals, pullRequest, publishWarning, walkthrough, earlierWalkthroughs, sharedFiles, brief, turnAnchors, isExpiredCoOwnerStop, messages, queuedMessages, isLegacy, legacyAnswers, localTurnStints, notice, composer, capacity, teleport, stackParent, modelLabels, question, request, progress, runDetails, milestones, runs, agents, slashCommands, openLog, card, repositoryQuestion, earlyWarning, coOwner, stopped, actions }: Props) {
    useFragmentScroll(thread.id, turnAnchors);
    const conversation = useMemo(() => withoutQueuedMessages(messages), [messages]);
    const dockPanels = dockPanelsFor(thread);
    const dock = useDockState();
    const dockPanelIds = dockPanels.map((panel) => panel.id);
    const activeDock = dockPanels.find((panel) => panel.id === dock.activeId) ?? null;
    const isWide = useMediaQuery(WIDE_RAIL_QUERY);
    const isDesktop = useMediaQuery(DESKTOP_QUERY);
    // On a phone the card scrolls with the conversation and only the composer stays pinned. The placement follows the
    // viewport; what was typed in the card is kept by the card drafts, so the move does not lose it.
    const isPhone = useMediaQuery('(max-width: 639px)');
    useEffect(() => clearCardDrafts(thread.id, card), [thread.id, card]);
    // What was typed in a card is dropped when the reader leaves the thread, so coming back starts with a clean card.
    useEffect(() => () => clearCardDrafts(thread.id, null), [thread.id]);
    const [isRailOpen, setIsRailOpen] = useState(() => activeDock === null || (activeDock.id !== 'files' && isWide));
    const [phoneTab, setPhoneTab] = useState<PhoneTabId>(() => phoneTabIdFor(activeDock?.id ?? null));
    const [filesMode, setFilesMode] = useState<FilesMode | null>(null);
    const [focusRequest, setFocusRequest] = useState<FocusRequest | null>(null);
    const focusCounter = useRef(0);
    const [logState, setLogState] = useState<{ threadId: string; target: RunLogTarget | null }>(() => ({ threadId: thread.id, target: openLog === null ? null : { kind: isLegacy ? 'task' : 'run', id: openLog.runId, entryId: openLog.entryId, headline: thread.title } }));
    // The overlay target belongs to the thread it was opened on; a visit to another thread starts without one.
    const logTarget = logState.threadId === thread.id ? logState.target : null;
    const setLogTarget = (target: RunLogTarget | null) => setLogState({ threadId: thread.id, target });
    const [walkthroughRequest] = useState(() => {
        const requested = new URLSearchParams(window.location.search).get('walkthrough');
        const seconds = requested === null || requested.trim() === '' ? NaN : Number(requested);

        return { threadId: thread.id, seconds: Number.isFinite(seconds) && seconds >= 0 ? seconds : null };
    });
    const walkthroughStartAt = walkthroughRequest.threadId === thread.id ? walkthroughRequest.seconds : null;
    const [liveAgents, setLiveAgents] = useState<Record<string, ThreadAgent>>({});
    const [liveSteps, setLiveSteps] = useState(0);
    const [selectedAgentId, setSelectedAgentId] = useState<string | null>(null);
    useEffect(() => setSelectedAgentId(null), [thread.id]);
    const [linkKind, setLinkKind] = useState<LinkState['kind']>('ok');
    const [restoredDraft, setRestoredDraft] = useState<{ text: string } | null>(null);
    const [isAddingSubThread, setIsAddingSubThread] = useState(false);
    const [isTeleportOpen, setIsTeleportOpen] = useState(false);
    const [isMoving, setIsMoving] = useState(false);
    const { stack, reload: reloadStack } = useStack(thread);
    usePullRequestToasts(thread, pullRequest, publishWarning);
    const { diff, isLoading: isDiffLoading } = useThreadDiff(thread);
    const canAddSubThread = canDrive && stack !== null && stack.canDrive && stack.isRoot && thread.ownerSide === 'yak' && thread.status === 'open';
    const subThreadCount = stack?.children.length ?? 0;
    const latestRun = runs.at(-1);
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
    const isPhoneDockVisible = phoneTab === 'preview' || phoneTab === 'files' || phoneTab === 'terminal' || phoneTab === 'agents';
    const openAgentsPanel = (agentId?: string) => {
        setSelectedAgentId(agentId ?? null);
        selectDock('agents');
        setPhoneTab('agents');
    };
    const openAgentLog = (agent: ThreadAgent) => setLogTarget({ kind: 'url', id: agentLog.url({ thread: thread.id, agent: agent.id }), entryId: null, headline: agentTitle(agent) });
    const changesCount = diff?.filesCount ?? 0;

    // The note says what changed at the latest move to Yak.
    const noteSwitch = [...teleport.environmentSwitches].reverse().find((entry) => entry.to === 'Yak');

    const panelProps: PanelProps = {
        thread,
        canDrive,
        notice,
        sharedFiles,
        request,
        coOwner,
        runDetails,
        milestones,
        runs,
        composerState: composer.state,
        composer,
        modelLabels,
        capacity,
        accountName: composer.accountName,
        environmentSwitch: teleport.environmentSwitches.at(-1) ?? null,
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

    const handlers: ShowActionOptions['handlers'] = {
        selectView: (id) => selectDock(id),
        openChanges: () => openChanges(),
        showConversation: () => panelProps.onShowConversation?.(),
        toggleOverview: () => (isDesktop ? setIsRailOpen((value) => !value) : openOverview()),
        addSubThread: () => setIsAddingSubThread(true),
        teleport: () => setIsTeleportOpen(true),
        openFile,
    };
    const pageHandlers = useRef(handlers);
    pageHandlers.current = handlers;
    const parent = breadcrumbParent(thread, stackParent);
    const teleportLabel = canTeleportThread(teleport) ? teleportLabelFor(teleport) : null;
    const previewTargets = thread.preview.targets.map((target) => ({ title: target.title, url: target.url }));
    const previewTargetsKey = JSON.stringify(previewTargets);
    const dockPanelKey = dockPanelIds.join(',');
    const hasChanges = diffTotals !== null;
    const parentId = parent?.id ?? null;
    const parentTitle = parent?.title ?? null;
    const threadActions = useMemo(
        () => [
            ...threadDataActions({ id: thread.id, branch: thread.branch, previewUrl: thread.preview.url, previewTargets: JSON.parse(previewTargetsKey), pullRequestUrl: pullRequest?.url ?? null }),
            ...showActions({
                threadId: thread.id,
                dockPanelIds: dockPanelKey === '' ? [] : dockPanelKey.split(','),
                hasChanges,
                parent: parentId === null || parentTitle === null ? null : { id: parentId, title: parentTitle },
                canAddSubThread,
                teleportLabel,
                handlers: {
                    selectView: (id) => pageHandlers.current.selectView(id),
                    openChanges: () => pageHandlers.current.openChanges(),
                    showConversation: () => pageHandlers.current.showConversation(),
                    toggleOverview: () => pageHandlers.current.toggleOverview(),
                    addSubThread: () => pageHandlers.current.addSubThread(),
                    teleport: () => pageHandlers.current.teleport(),
                    openFile: (path) => pageHandlers.current.openFile(path),
                },
            }),
        ],
        [thread.id, thread.branch, thread.preview.url, previewTargetsKey, pullRequest?.url, dockPanelKey, hasChanges, parentId, parentTitle, canAddSubThread, teleportLabel],
    );
    useCommandPaletteActions(THREAD_GROUP, threadActions);

    return (
        <AgentsProvider threadId={thread.id} canDrive={canDrive} loaded={agents} live={liveAgents} selectedId={selectedAgentId} onSelect={setSelectedAgentId} openPanel={openAgentsPanel} openFullLog={openAgentLog}>
            <Head title={thread.title} />

            <ThreadHeader
                page={{ thread, canDrive, diffTotals, pullRequest, publishWarning, walkthrough, notice, composer, stackParent, request, actions, coOwner, stopped }}
                dockPanels={dockPanels}
                activeDock={activeDock}
                diff={diff}
                subThreadCount={subThreadCount}
                isRailOpen={isRailOpen}
                onSelectDock={(id) => selectDock(id)}
                onToggleRail={() => setIsRailOpen((value) => !value)}
                onOpenChanges={() => openChanges()}
                onOpenOverview={openOverview}
                onMove={() => setIsMoving(true)}
                failedStepLabel={failedStepReason(progress?.steps.find((step) => step.state === 'failed')?.label ?? null)}
                isLegacy={isLegacy}
            />
            {progress !== null && <ProgressRow progress={progress} isShownOnPhone={isProgressShownOnPhone(request.status.key)} />}
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
                    {isLegacy ? <LegacyConversation messages={messages} answers={legacyAnswers} milestones={milestones} onOpenLog={(taskId) => setLogTarget({ kind: 'task', id: taskId, entryId: null, headline: thread.title })} /> : <Conversation isWaitingForRepository={card === 'repository_question'} runs={runs} milestones={milestones} walkthroughStartAt={walkthroughStartAt} onOpenRunLog={(runId) => setLogTarget({ kind: 'run', id: runId, entryId: null, headline: thread.title })} working={composer.state === 'working'} modelLabels={modelLabels} threadId={thread.id} title={thread.title} repo={thread.repo} branch={thread.branch} messages={conversation} question={question} walkthrough={walkthrough} earlierWalkthroughs={earlierWalkthroughs} sharedFiles={sharedFiles} isTeamThread={thread.visibility === 'team'} canShareFiles={canDrive} localTurns={localTurnStints} notice={notice} readonly={composer.state === 'readonly'} environmentNote={noteSwitch === undefined || teleport.environmentNote === null ? null : { text: teleport.environmentNote, ...noteSwitch }} environmentSwitches={teleport.environmentSwitches} pullRequestCount={thread.pullRequestCount} lastActivityAt={thread.lastActivityAt} onLinkChange={setLinkKind} onAgentsChange={setLiveAgents} onLiveStepsChange={setLiveSteps} onEditUnsent={(text) => setRestoredDraft({ text })} onOpenChanges={openChanges} footer={isPhone ? <ThreadCards isInline={isPhone} page={{ thread, card, repositoryQuestion, earlyWarning, coOwner, stopped, actions, isExpiredCoOwnerStop, request }} onOpenChanges={() => openChanges()} onMove={() => setIsMoving(true)} onShowLog={latestRun === undefined ? null : () => setLogTarget({ kind: 'run', id: latestRun.runId, entryId: null, headline: thread.title })} /> : null} />}
                    {!isPhone && <ThreadCards page={{ thread, card, repositoryQuestion, earlyWarning, coOwner, stopped, actions, isExpiredCoOwnerStop, request }} onOpenChanges={() => openChanges()} onMove={() => setIsMoving(true)} onShowLog={latestRun === undefined ? null : () => setLogTarget({ kind: 'run', id: latestRun.runId, entryId: null, headline: thread.title })} />}
                    <ThreadComposer liveSteps={liveSteps} slashCommands={slashCommands} isWaitingForRepository={card === 'repository_question'} thread={thread} composer={composer} queued={queuedMessages} notice={notice} teleportState={teleport.state} isOffline={linkKind === 'offline'} restoredDraft={restoredDraft} request={request} onAddSubThread={canAddSubThread ? () => setIsAddingSubThread(true) : undefined} />
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

            <MoveRepositoryDialog threadId={thread.id} targets={actions.moveTargets} open={isMoving} onOpenChange={setIsMoving} />
            <RunLogOverlay threadId={thread.id} target={logTarget} runs={isLegacy ? [] : runs.map((run) => ({ target: { kind: 'run', id: run.runId, entryId: null, headline: thread.title }, meta: runLogMeta(run, modelLabels) }))} onSelectRun={setLogTarget} isLive={logTarget?.kind === 'run' && runs.find((run) => run.runId === logTarget.id)?.isActive === true} onClose={() => setLogTarget(null)} />
            {isAddingSubThread && stack !== null && (
                <AddChildDialog threadId={thread.id} parentTitle={thread.title} stack={{ models: stack.models, parentAccountName: stack.parentAccountName, maxChildren: stack.maxChildren, awake: stack.limit.awake, limit: stack.limit.current, childCount: stack.children.length }} onClose={() => setIsAddingSubThread(false)} onAdded={() => { reloadStack(); openOverview(); }} />
            )}
            {teleport.state === 'on_device' ? (
                <TeleportToYakDialog thread={thread} teleport={teleport} open={isTeleportOpen} onClose={() => setIsTeleportOpen(false)} />
            ) : (
                <TeleportToComputerDialog thread={thread} teleport={teleport} open={isTeleportOpen} onClose={() => setIsTeleportOpen(false)} />
            )}
        </AgentsProvider>
    );
}

Show.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
