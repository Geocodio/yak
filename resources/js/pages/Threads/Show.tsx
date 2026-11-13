import { Head } from '@inertiajs/react';
import { cn } from '@geocodio/console-ui';
import { useState, type ReactNode } from 'react';
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
import { phoneDockIdFor, phoneTabIdFor, type PhoneTabId, type PhoneTerminalMode } from '@/threads/dock/dockState';
import { PhoneTerminalSwitch } from '@/threads/dock/PhoneTerminalSwitch';
import { ThreadDockPanel } from '@/threads/dock/ThreadDock';
import { ThreadPhoneTabs } from '@/threads/dock/ThreadPhoneTabs';
import { useDockState } from '@/threads/dock/useDockState';
import { dockPanelsFor, type PanelProps } from '@/threads/panels';
import '@/threads/panels/index';
import { Overview } from '@/threads/panels/environment/Overview';
import { AddChildDialog } from '@/threads/panels/stack/AddChildDialog';
import { useStack } from '@/threads/panels/stack/useStack';
import { useThreadDiff } from '@/threads/panels/changes/useThreadDiff';
import type { LinkState } from '@/threads/realtime/catchUpRunner';
import type { PageProps } from '@/types/shared';
import type { ThreadPageData } from '@/types/threads';

type Props = PageProps<ThreadPageData>;

/** Below this width the rail floats over the open view instead of sitting beside it. */
const WIDE_RAIL_QUERY = '(min-width: 1200px)';

export default function Show({ thread, canDrive, diffTotals, pullRequest, publishWarning, brief, messages, notice, composer, capacity, teleport, stackParent, modelLabels }: Props) {
    const dockPanels = dockPanelsFor(thread);
    const dock = useDockState();
    const dockPanelIds = dockPanels.map((panel) => panel.id);
    const activeDock = dockPanels.find((panel) => panel.id === dock.activeId) ?? null;
    const isWide = useMediaQuery(WIDE_RAIL_QUERY);
    const [isRailOpen, setIsRailOpen] = useState(() => activeDock === null || (activeDock.id !== 'changes' && isWide));
    const [phoneTerminalMode, setPhoneTerminalMode] = useState<PhoneTerminalMode>(() => (dock.activeId === 'files' ? 'files' : 'shell'));
    const [phoneTab, setPhoneTab] = useState<PhoneTabId>(() => phoneTabIdFor(activeDock?.id ?? null));
    const [focusPath, setFocusPath] = useState<string | null>(null);
    const [linkKind, setLinkKind] = useState<LinkState['kind']>('ok');
    const [restoredDraft, setRestoredDraft] = useState<{ text: string } | null>(null);
    const [isAddingSubThread, setIsAddingSubThread] = useState(false);
    const [isTeleportOpen, setIsTeleportOpen] = useState(false);
    const { stack, reload: reloadStack } = useStack(thread);
    const { diff } = useThreadDiff(thread);
    const canAddSubThread = canDrive && stack !== null && stack.canDrive && stack.isRoot && thread.ownerSide === 'yak' && thread.status === 'open';
    const subThreadCount = stack?.children.length ?? 0;
    const isOverlayRail = isRailOpen && !isWide && activeDock !== null;

    /** Opens a view and decides the rail: Changes takes the full width, a narrow screen floats the rail, otherwise it stays beside the view. */
    const selectDock = (id: string | null, path: string | null = null) => {
        dock.setActiveId(id);
        setFocusPath(path);
        if (id === 'changes' || (id !== null && !isWide)) {
            setIsRailOpen(false);
        } else if (isWide) {
            setIsRailOpen(true);
        }
    };
    const openChanges = (path?: string) => {
        selectDock('changes', path ?? null);
        setPhoneTab('changes');
    };
    const openFile = (path: string) => {
        selectDock('files', path);
        setPhoneTab('terminal');
        setPhoneTerminalMode('files');
    };
    const openOverview = () => {
        setIsRailOpen(true);
        setPhoneTab('overview');
    };
    const selectPhoneTab = (id: PhoneTabId) => {
        setPhoneTab(id);
        const dockId = phoneDockIdFor(id, phoneTerminalMode);
        if (dockId !== null) {
            dock.setActiveId(dockId);
        }
    };
    const selectPhoneTerminalMode = (mode: PhoneTerminalMode) => {
        setPhoneTerminalMode(mode);
        dock.setActiveId(phoneDockIdFor('terminal', mode));
    };
    const isPhoneDockVisible = phoneTab === 'preview' || phoneTab === 'changes' || phoneTab === 'terminal';
    const changesCount = diff?.filesCount ?? 0;

    const panelProps: PanelProps = {
        thread,
        canDrive,
        notice,
        composerState: composer.state,
        capacity,
        accountName: composer.accountName,
        environmentSwitch: teleport.environmentSwitch,
        stack,
        reloadStack,
        diff,
        focusPath,
        onOpenChanges: openChanges,
        onOpenFile: openFile,
        onSelectView: (id) => selectDock(id),
        onCloseRail: isOverlayRail ? () => setIsRailOpen(false) : undefined,
        onAddSubThread: canAddSubThread ? () => setIsAddingSubThread(true) : undefined,
        onTeleport: () => setIsTeleportOpen(true),
    };

    return (
        <>
            <Head title={thread.title} />

            <ThreadHeader
                page={{ thread, canDrive, diffTotals, pullRequest, publishWarning, notice, composer, stackParent, teleport }}
                dockPanels={dockPanels}
                activeDock={activeDock}
                diff={diff}
                subThreadCount={subThreadCount}
                canAddSubThread={canAddSubThread}
                isRailOpen={isRailOpen}
                onSelectDock={(id) => selectDock(id)}
                onToggleRail={() => setIsRailOpen((value) => !value)}
                onOpenOverview={openOverview}
                onAddSubThread={() => setIsAddingSubThread(true)}
                onTeleport={() => setIsTeleportOpen(true)}
            />
            <FailedStepStrip notice={notice} threadId={thread.id} readonly={composer.state === 'readonly'} />
            <PrivateThreadBanner thread={thread} />
            <ChildParentBanner thread={thread} parent={stackParent} hasBrief={brief !== null} />
            <ThreadPhoneTabs registeredDockIds={dockPanelIds} active={phoneTab} isWorking={thread.runnerState === 'busy'} changesCount={changesCount} onSelect={selectPhoneTab} />
            {phoneTab === 'terminal' && <PhoneTerminalSwitch mode={phoneTerminalMode} onChange={selectPhoneTerminalMode} />}
            {isPhoneDockVisible && <ClaudeWorkingStrip thread={thread} onOpenChat={() => setPhoneTab('chat')} />}

            <div className="relative flex min-h-0 flex-1">
                <div
                    className={cn('min-w-0 flex-1 flex-col bg-app lg:flex', phoneTab === 'chat' ? 'flex' : 'hidden', activeDock !== null && 'lg:w-[380px] lg:flex-none')}
                    data-testid="thread-conversation"
                >
                    {brief !== null && stackParent !== null && <ChildBriefCard brief={brief} parentTitle={stackParent.title} />}
                    <TeleportConversationNotices thread={thread} teleport={teleport} />
                    <Conversation modelLabels={modelLabels} threadId={thread.id} title={thread.title} repo={thread.repo} branch={thread.branch} messages={messages} notice={notice} readonly={composer.state === 'readonly'} environmentNote={teleport.environmentNote === null || teleport.environmentSwitch === null ? null : { text: teleport.environmentNote, ...teleport.environmentSwitch }} environmentSwitch={teleport.environmentSwitch} pullRequestCount={thread.pullRequestCount} lastActivityAt={thread.lastActivityAt} onLinkChange={setLinkKind} onEditUnsent={(text) => setRestoredDraft({ text })} onOpenChanges={openChanges} />
                    <ThreadComposer thread={thread} composer={composer} notice={notice} teleportState={teleport.state} isOffline={linkKind === 'offline'} restoredDraft={restoredDraft} onAddSubThread={canAddSubThread ? () => setIsAddingSubThread(true) : undefined} />
                </div>

                <ThreadDockPanel active={activeDock} isPhoneVisible={isPhoneDockVisible} panelProps={panelProps} />

                <aside
                    aria-label="Overview"
                    className={cn(
                        'w-full shrink-0 flex-col overflow-y-auto bg-app lg:w-[312px] lg:border-l lg:border-hair',
                        phoneTab === 'overview' ? 'flex' : 'hidden',
                        isRailOpen ? 'lg:flex' : 'lg:hidden',
                        isOverlayRail && 'lg:absolute lg:inset-y-0 lg:right-0 lg:z-20 lg:shadow-[-14px_0_30px_-18px_rgb(0_0_0/0.35)]',
                    )}
                    data-testid="thread-sidebar"
                >
                    <Overview {...panelProps} />
                </aside>
            </div>

            {isAddingSubThread && stack !== null && (
                <AddChildDialog threadId={thread.id} parentTitle={thread.title} awake={stack.limit.awake} limit={stack.limit.current} childCount={stack.children.length} maxChildren={stack.maxChildren} onClose={() => setIsAddingSubThread(false)} onAdded={() => { reloadStack(); openOverview(); }} />
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
