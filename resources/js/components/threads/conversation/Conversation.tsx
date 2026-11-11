import { Fragment, useState } from 'react';
import { MediaLightbox } from '@/components/tasks/MediaLightbox';
import type { MediaItem } from '@/types/tasks';
import type { ThreadMessage, ThreadNotice as ThreadNoticeData } from '@/types/threads';
import { ChildCard } from './ChildCard';
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
import { ThreadNotice } from './ThreadNotice';
import { ThreadTitleBlock } from './ThreadTitleBlock';
import { ToolActivity } from './ToolActivity';
import { WorkingBlock } from './WorkingBlock';
import type { ConversationItem } from './conversationItem';

// Browser tests drive the pure merge through this hook.
Object.assign(window, { __mergeConversation: mergeConversation, __createReadTracker: createReadTracker });

function EventRow({ item, canDrive, isLast }: { item: ConversationItem; canDrive: boolean; isLast: boolean }) {
    if (item.kind === 'child') {
        return <ChildCard item={item} canDrive={canDrive} />;
    }

    if (item.kind === 'repository') {
        return <RepositoryCard item={item} />;
    }

    if (item.kind === 'tools') {
        if (item.running && isLast) {
            return <WorkingBlock items={[item]} />;
        }

        return <ToolActivity item={item} />;
    }

    return item.kind === 'edits' ? <EditsBlock item={item} /> : <ClaudeMessage item={item} />;
}

export function Conversation({ threadId, repo, branch, messages, notice, readonly, pullRequestCount = 0, lastActivityAt = null, title = null, environmentNote = null, environmentSwitch = null }: { title?: string | null; environmentNote?: { text: string; from: string; to: string; at: string } | null; environmentSwitch?: { from: string; to: string; at: string } | null; threadId: number; repo: string; branch: string | null; messages: ThreadMessage[]; notice: ThreadNoticeData | null; readonly: boolean; pullRequestCount?: number; lastActivityAt?: string | null }) {
    const { entries, lastSequence, connection } = useConversation(threadId, messages);
    const [lightboxMedia, setLightboxMedia] = useState<MediaItem[] | null>(null);
    const [lightboxIndex, setLightboxIndex] = useState(0);
    let previousDay = '';

    return (
        <div className="min-h-0 flex-1 overflow-auto px-4 py-5 sm:px-8">
            <div className="mx-auto flex max-w-[760px] flex-col gap-4">
                {title && <ThreadTitleBlock title={title} repo={repo} branch={branch} />}
                {entries.map((entry, index) => {
                    if (entry.type === 'event') {
                        return <EventRow key={entry.item.key} item={entry.item} canDrive={!readonly} isLast={index === entries.length - 1} />;
                    }

                    const day = entry.message.sentAt.slice(0, 10);
                    const showDay = day !== previousDay;
                    previousDay = day;

                    return (
                        <Fragment key={entry.message.uuid ?? entry.message.id}>
                            {showDay && <DayDivider iso={entry.message.sentAt} />}
                            <PersonMessage
                                message={entry.message}
                                pending={entry.pending}
                                onOpenMedia={(media, index) => {
                                    setLightboxMedia(media);
                                    setLightboxIndex(index);
                                }}
                            />
                        </Fragment>
                    );
                })}
                {environmentSwitch && <SideSwitchDivider {...environmentSwitch} />}
                {environmentNote && <EnvironmentNoteCard threadId={threadId} text={environmentNote.text} from={environmentNote.from} to={environmentNote.to} />}
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
            <MediaLightbox media={lightboxMedia} index={lightboxIndex} onOpenChange={(open) => !open && setLightboxMedia(null)} onIndexChange={setLightboxIndex} />
        </div>
    );
}
