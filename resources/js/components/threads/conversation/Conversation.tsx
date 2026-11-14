import { router, usePoll } from '@inertiajs/react';
import { ArrowDown } from 'lucide-react';
import { Fragment, useEffect, useState } from 'react';
import { MediaLightbox } from '@/components/tasks/MediaLightbox';
import type { MediaItem } from '@/types/tasks';
import type { LocalTurnsData, SharedFile, ThreadMessage, ThreadNotice as ThreadNoticeData, ThreadWalkthrough } from '@/types/threads';
import { outbox, useUnsentMessages } from '@/threads/outbox/useOutbox';
import type { LinkState } from '@/threads/realtime/catchUpRunner';
import { ChildCard } from './ChildCard';
import { ConnectionStrip } from './ConnectionStrip';
import { LocalTurnsFold } from './LocalTurnsFold';
import { UnsentMessage } from './UnsentMessage';
import { useStickToBottom } from './useStickToBottom';
import { RepositoryCard } from './RepositoryCard';
import { ClaudeMessage } from './ClaudeMessage';
import { DayDivider } from './DayDivider';
import { EditsBlock } from './EditsBlock';
import { createReadTracker } from './mergeConversation';
import { mergeConversation, useConversation } from './useConversation';
import { PersonMessage } from './PersonMessage';
import { PullRequestChips } from './PullRequestChip';
import { EnvironmentNoteCard } from './EnvironmentNoteCard';
import { SideSwitchDivider } from './SideSwitchDivider';
import { switchPosition } from './switchPosition';
import { ThreadNotice } from './ThreadNotice';
import { ToolSteps } from './ToolSteps';
import { WalkthroughCard } from './WalkthroughCard';
import { SharedFilesCard } from './SharedFilesCard';
import { placeSharedFiles } from '@/components/threads/files/sharedFiles';
import type { ConversationItem } from './conversationItem';

// Browser tests drive the pure merge through this hook.
Object.assign(window, { __mergeConversation: mergeConversation, __createReadTracker: createReadTracker });

function EventRow({ item, canDrive, isLast, threadId, modelLabels, onOpenChanges }: { onOpenChanges?: (path?: string) => void; modelLabels: Record<string, string>; item: ConversationItem; canDrive: boolean; isLast: boolean; threadId: string }) {
    if (item.kind === 'child') {
        return <ChildCard item={item} canDrive={canDrive} />;
    }

    if (item.kind === 'repository') {
        const repositoryId = item.repository?.id ?? null;
        const send = (method: 'patch' | 'post', suffix: string, data: Record<string, string>) =>
            router[method](`/threads/${threadId}/repositories/${repositoryId}${suffix}`, data, { preserveScroll: true, preserveState: true });
        const canAct = canDrive && repositoryId !== null;

        // No Remove: the backend has no destroy route for a thread repository.
        return (
            <RepositoryCard
                item={item}
                onRetrySetup={canAct ? () => send('post', '/retry', {}) : undefined}
                onMakeContextOnly={canAct ? () => send('patch', '', { role: 'context' }) : undefined}
            />
        );
    }

    if (item.kind === 'tools') {
        return <ToolSteps item={item} live={item.running === true && isLast} />;
    }

    return item.kind === 'edits' ? <EditsBlock item={item} onOpenChanges={onOpenChanges} /> : <ClaudeMessage item={item} modelLabels={modelLabels} />;
}

export function Conversation({ modelLabels = {}, threadId, repo, branch, messages, walkthrough = null, sharedFiles = [], isTeamThread = false, canShareFiles = false, localTurns = null, notice, readonly, pullRequestCount = 0, lastActivityAt = null, title = null, environmentNote = null, environmentSwitch = null, onLinkChange, onEditUnsent, onOpenChanges }: { onOpenChanges?: (path?: string) => void; modelLabels?: Record<string, string>; onLinkChange?: (kind: LinkState['kind']) => void; onEditUnsent?: (text: string) => void; title?: string | null; environmentNote?: { text: string; from: string; to: string; at: string } | null; environmentSwitch?: { from: string; to: string; at: string } | null; threadId: string; repo: string; branch: string | null; messages: ThreadMessage[]; walkthrough?: ThreadWalkthrough | null; sharedFiles?: SharedFile[]; isTeamThread?: boolean; canShareFiles?: boolean; localTurns?: LocalTurnsData | null; notice: ThreadNoticeData | null; readonly: boolean; pullRequestCount?: number; lastActivityAt?: string | null }) {
    const { entries, lastSequence, connection, link } = useConversation(threadId, messages);
    const unsent = useUnsentMessages(threadId);
    const { scroller, content, hasNew, jumpToNewest } = useStickToBottom(threadId);

    useEffect(() => onLinkChange?.(link.kind), [link.kind, onLinkChange]);
    // The person's own message always comes into view, also after they scrolled up.
    useEffect(() => {
        if (unsent.length > 0) {
            jumpToNewest();
        }
    }, [unsent.length, jumpToNewest]);
    // New files arrive after a turn ends; the card appears without a reload.
    usePoll(10_000, { only: ['sharedFiles'] });
    const { after: filesAfter, trailing: trailingFiles } = placeSharedFiles(entries, sharedFiles);
    const [lightboxMedia, setLightboxMedia] = useState<MediaItem[] | null>(null);
    const [lightboxIndex, setLightboxIndex] = useState(0);
    let previousDay = '';
    const switchAt = environmentSwitch?.at ?? environmentNote?.at ?? null;
    const switchIndex = switchAt === null ? -1 : switchPosition(entries, switchAt);
    const switchBlock = (
        <Fragment key="environment-switch">
            {environmentSwitch && <SideSwitchDivider {...environmentSwitch} />}
            {environmentNote && <EnvironmentNoteCard threadId={threadId} text={environmentNote.text} from={environmentNote.from} to={environmentNote.to} />}
        </Fragment>
    );

    return (
        <>
        <div ref={scroller} className="min-h-0 flex-1 overflow-auto px-4 py-5 sm:px-8" data-testid="thread-conversation-scroller">
            <div ref={content} className="mx-auto flex max-w-[760px] flex-col gap-4">
                {localTurns && <LocalTurnsFold threadId={threadId} localTurns={localTurns} modelLabels={modelLabels} />}
                {entries.map((entry, index) => {
                    const before = index === switchIndex ? switchBlock : null;

                    if (entry.type === 'event') {
                        return (
                            <Fragment key={entry.item.key}>
                                {before}
                                <EventRow modelLabels={modelLabels} key={entry.item.key} item={entry.item} canDrive={!readonly} isLast={index === entries.length - 1} threadId={threadId} onOpenChanges={onOpenChanges} />
                                {(filesAfter.get(index) ?? []).map((group) => (
                                    <SharedFilesCard key={group[0].id} files={group} threadId={threadId} canShare={canShareFiles} isTeamThread={isTeamThread} />
                                ))}
                            </Fragment>
                        );
                    }

                    const day = entry.message.sentAt.slice(0, 10);
                    const showDay = day !== previousDay;
                    previousDay = day;

                    return (
                        <Fragment key={entry.message.uuid ?? entry.message.id}>
                            {before}
                            {showDay && <DayDivider iso={entry.message.sentAt} />}
                            <PersonMessage
                                message={entry.message}
                                pending={entry.pending}
                                modelLabels={modelLabels}
                                onOpenMedia={(media, index) => {
                                    setLightboxMedia(media);
                                    setLightboxIndex(index);
                                }}
                            />
                        </Fragment>
                    );
                })}
                {unsent.map((message) => (
                    <UnsentMessage key={message.key} message={message} onRetry={() => outbox.retry(threadId, message.key)} onEdit={() => onEditUnsent?.(outbox.cancel(threadId, message.key))} />
                ))}
                {walkthrough !== null && <WalkthroughCard walkthrough={walkthrough} />}
                {trailingFiles.length > 0 && <SharedFilesCard files={trailingFiles} threadId={threadId} canShare={canShareFiles} isTeamThread={isTeamThread} />}
                {switchIndex === entries.length && switchBlock}
                {pullRequestCount > 1 && <PullRequestChips threadId={threadId} lastActivityAt={lastActivityAt} />}
                <span
                    hidden
                    data-testid="thread-event-state"
                    data-last-sequence={lastSequence}
                    data-status={connection}
                    data-item-keys={entries.flatMap((entry) => (entry.type === 'event' ? [entry.item.key] : [])).join(',')}
                />
                {notice && <ThreadNotice notice={notice} threadId={threadId} repo={repo} branch={branch} readonly={readonly} />}
            </div>
            {hasNew && (
                <button type="button" onClick={jumpToNewest} data-testid="thread-jump-to-newest" className="sticky bottom-1 mx-auto mt-3 flex w-fit cursor-pointer items-center gap-1.5 rounded-pill border border-hair bg-panel px-3 py-1.5 text-[12px] font-medium text-body shadow-card">
                    <ArrowDown size={13} aria-hidden />
                    New reply
                </button>
            )}
            <MediaLightbox media={lightboxMedia} index={lightboxIndex} onOpenChange={(open) => !open && setLightboxMedia(null)} onIndexChange={setLightboxIndex} />
        </div>
        <ConnectionStrip link={link} />
        </>
    );
}
