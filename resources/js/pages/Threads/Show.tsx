import { Head, Link } from '@inertiajs/react';
import { cn } from '@geocodio/console-ui';
import { PanelRight } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import { StatusPill } from '@/components/threads/StatusPill';
import { ThreadComposer } from '@/components/threads/composer/ThreadComposer';
import { FailedStepStrip } from '@/components/threads/conversation/FailedStepStrip';
import { Conversation } from '@/components/threads/conversation/Conversation';
import { TeleportConversationNotices } from '@/components/threads/teleport/TeleportConversationNotices';
import { AppLayout } from '@/layouts/AppLayout';
import { ClaudeWorkingStrip } from '@/threads/dock/ClaudeWorkingStrip';
import { phoneDockIdFor, type PhoneTabId, type PhoneTerminalMode } from '@/threads/dock/dockState';
import { PhoneTerminalSwitch } from '@/threads/dock/PhoneTerminalSwitch';
import { ThreadDockPanel, ThreadDockToggles } from '@/threads/dock/ThreadDock';
import { ThreadPhoneTabs } from '@/threads/dock/ThreadPhoneTabs';
import { useDockState } from '@/threads/dock/useDockState';
import { dockPanelsFor, sidebarPanelsFor } from '@/threads/panels';
import '@/threads/panels/index';
import { ChildBriefCard } from '@/components/threads/header/ChildBriefCard';
import { ThreadHeaderMeta } from '@/components/threads/header/ThreadHeaderMeta';
import { ChildParentBanner } from '@/components/threads/stack/ChildParentBanner';
import { PrivateThreadBanner } from '@/components/threads/share/PrivateThreadBanner';
import { ShareButton } from '@/components/threads/share/ShareButton';
import { VisibilityBadge } from '@/components/threads/share/VisibilityBadge';
import { show } from '@/routes/threads';
import type { LinkState } from '@/threads/realtime/catchUpRunner';
import type { PageProps } from '@/types/shared';
import type { ThreadPageData } from '@/types/threads';

type Props = PageProps<ThreadPageData>;

export default function Show({ thread, canDrive, diffTotals, pullRequest, brief, messages, notice, composer, capacity, teleport, stackParent, modelLabels }: Props) {
    const panels = sidebarPanelsFor(thread);
    const [activeId, setActiveId] = useState<string | null>(null);
    const active = panels.find((panel) => panel.id === activeId) ?? panels[0] ?? null;
    const dockPanels = dockPanelsFor(thread);
    const dock = useDockState();
    const dockPanelIds = dockPanels.map((panel) => panel.id);
    const [phoneTerminalMode, setPhoneTerminalMode] = useState<PhoneTerminalMode>(() => (dock.activeId === 'files' ? 'files' : 'shell'));
    const activeDock = dockPanels.find((panel) => panel.id === dock.activeId) ?? null;
    const [phoneTab, setPhoneTab] = useState<PhoneTabId>(() => (activeDock === null ? 'chat' : activeDock.id === 'files' ? 'terminal' : activeDock.id === 'preview' || activeDock.id === 'terminal' ? activeDock.id : 'chat'));
    const [isDetailsSheetOpen, setIsDetailsSheetOpen] = useState(false);
    const [linkKind, setLinkKind] = useState<LinkState['kind']>('ok');
    const [restoredDraft, setRestoredDraft] = useState<{ text: string } | null>(null);
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
    const isPhoneDockVisible = phoneTab === 'preview' || phoneTab === 'terminal';
    const isDocked = activeDock !== null;

    useEffect(() => {
        if (!isDocked || !isDetailsSheetOpen) {
            return;
        }

        document.body.dataset.threadDetailsOpen = 'true';

        return () => {
            delete document.body.dataset.threadDetailsOpen;
        };
    }, [isDocked, isDetailsSheetOpen]);

    return (
        <>
            <Head title={thread.title} />

            <header className="shrink-0 border-b border-hair px-4 py-3 sm:px-8" data-testid="thread-header">
                <div className="flex items-center justify-between gap-3">
                    <div className="min-w-0">
                        <div className="flex items-center gap-2">
                            <h1 className="truncate text-[15px] font-semibold text-body" data-testid="thread-title">
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
                        <ThreadHeaderMeta thread={thread} diffTotals={diffTotals} pullRequest={pullRequest} />
                    </div>
                    <div className="flex shrink-0 items-center gap-2">
                        {isDocked && (
                            <button
                                type="button"
                                aria-pressed={isDetailsSheetOpen}
                                aria-label="Thread details"
                                data-testid="thread-details-sheet-toggle"
                                onClick={() => setIsDetailsSheetOpen((value) => !value)}
                                className="hidden size-8 cursor-pointer items-center justify-center rounded-chip border border-hair text-muted hover:text-body lg:flex xl:hidden"
                            >
                                <PanelRight size={14} />
                            </button>
                        )}
                        <ThreadDockToggles panels={dockPanels} active={activeDock} onSelect={dock.setActiveId} />
                        <ShareButton thread={thread} />
                    </div>
                </div>
            </header>
            <FailedStepStrip notice={notice} threadId={thread.id} readonly={composer.state === 'readonly'} />
            <PrivateThreadBanner thread={thread} />
            <ChildParentBanner thread={thread} parent={stackParent} hasBrief={brief !== null} />
            <ThreadPhoneTabs registeredDockIds={dockPanelIds} active={phoneTab} isWorking={thread.runnerState === 'busy'} onSelect={selectPhoneTab} />
            {phoneTab === 'terminal' && <PhoneTerminalSwitch mode={phoneTerminalMode} onChange={selectPhoneTerminalMode} />}
            {isPhoneDockVisible && <ClaudeWorkingStrip thread={thread} onOpenChat={() => setPhoneTab('chat')} />}

            <div className="flex min-h-0 flex-1">
                <div
                    className={cn('min-w-0 flex-1 flex-col lg:flex', phoneTab === 'chat' ? 'flex' : 'hidden', activeDock !== null && 'lg:w-[420px] lg:flex-none')}
                    data-testid="thread-conversation"
                >
                    {brief !== null && stackParent !== null && <ChildBriefCard brief={brief} parentTitle={stackParent.title} />}
                    <TeleportConversationNotices thread={thread} teleport={teleport} />
                    <Conversation modelLabels={modelLabels} threadId={thread.id} title={thread.title} repo={thread.repo} branch={thread.branch} messages={messages} notice={notice} readonly={composer.state === 'readonly'} environmentNote={teleport.environmentNote === null || teleport.environmentSwitch === null ? null : { text: teleport.environmentNote, ...teleport.environmentSwitch }} environmentSwitch={teleport.environmentSwitch} pullRequestCount={thread.pullRequestCount} lastActivityAt={thread.lastActivityAt} onLinkChange={setLinkKind} onEditUnsent={(text) => setRestoredDraft({ text })} />
                    <ThreadComposer thread={thread} composer={composer} notice={notice} teleportState={teleport.state} isOffline={linkKind === 'offline'} restoredDraft={restoredDraft} />
                </div>

                <ThreadDockPanel thread={thread} canDrive={canDrive} active={activeDock} isPhoneVisible={isPhoneDockVisible} />

                {panels.length > 0 && active !== null && (
                    <aside
                        className={cn('w-full shrink-0 flex-col border-l border-hair bg-sidebar lg:w-[320px] lg:flex', phoneTab === 'details' ? 'flex' : 'hidden')}
                        data-testid="thread-sidebar"
                    >
                        <div role="tablist" aria-label="Thread panels" className="flex shrink-0 gap-4 border-b border-hair px-4">
                            {panels.map((panel) => (
                                <button
                                    key={panel.id}
                                    type="button"
                                    role="tab"
                                    aria-selected={panel.id === active.id}
                                    data-testid={`thread-panel-tab-${panel.id}`}
                                    onClick={() => setActiveId(panel.id)}
                                    className={cn('flex h-11 items-center text-[14px]', panel.id === active.id ? 'font-medium text-body' : 'text-muted')}
                                >
                                    {panel.label}
                                    {panel.count && <span className="ml-1.5 rounded-pill bg-panel-2 px-1.5 text-[11px] text-muted" data-testid={`thread-panel-count-${panel.id}`}>{panel.count(thread)}</span>}
                                    {panel.badge && <span className="ml-1.5 rounded-pill bg-panel-2 px-1.5 text-[11px] text-muted empty:hidden" data-testid={`thread-panel-count-${panel.id}`}><panel.badge thread={thread} /></span>}
                                </button>
                            ))}
                        </div>
                        <div className="min-h-0 flex-1 overflow-auto px-4 py-5">
                            <active.component thread={thread} canDrive={canDrive} notice={notice} composerState={composer.state} capacity={capacity} accountName={composer.accountName} environmentSwitch={teleport.environmentSwitch} />
                        </div>
                    </aside>
                )}
            </div>
        </>
    );
}

Show.layout = (page: ReactNode) => <AppLayout>{page}</AppLayout>;
