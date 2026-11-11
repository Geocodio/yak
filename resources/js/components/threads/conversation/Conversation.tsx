import { Fragment, useState } from 'react';
import { MediaLightbox } from '@/components/tasks/MediaLightbox';
import type { MediaItem } from '@/types/tasks';
import type { ThreadMessage, ThreadNotice as ThreadNoticeData } from '@/types/threads';
import { ClaudeMessage } from './ClaudeMessage';
import { DayDivider } from './DayDivider';
import { EditsBlock } from './EditsBlock';
import { createReadTracker } from './mergeConversation';
import { mergeConversation, useConversation } from './useConversation';
import { PersonMessage } from './PersonMessage';
import { ThreadNotice } from './ThreadNotice';
import { ToolActivity } from './ToolActivity';
import type { ConversationItem } from './conversationItem';

// Browser tests drive the pure merge through this hook.
Object.assign(window, { __mergeConversation: mergeConversation, __createReadTracker: createReadTracker });

function EventRow({ item }: { item: ConversationItem }) {
    if (item.kind === 'tools') {
        return <ToolActivity item={item} />;
    }

    return item.kind === 'edits' ? <EditsBlock item={item} /> : <ClaudeMessage item={item} />;
}

export function Conversation({ threadId, messages, notice, readonly }: { threadId: number; messages: ThreadMessage[]; notice: ThreadNoticeData | null; readonly: boolean }) {
    const { entries, lastSequence, connection } = useConversation(threadId, messages);
    const [lightboxMedia, setLightboxMedia] = useState<MediaItem[] | null>(null);
    const [lightboxIndex, setLightboxIndex] = useState(0);
    let previousDay = '';

    return (
        <div className="min-h-0 flex-1 overflow-auto px-4 py-5 sm:px-8">
            <div className="mx-auto flex max-w-[760px] flex-col gap-4">
                {entries.map((entry) => {
                    if (entry.type === 'event') {
                        return <EventRow key={entry.item.key} item={entry.item} />;
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
                <span
                    hidden
                    data-testid="thread-event-state"
                    data-last-sequence={lastSequence}
                    data-status={connection}
                    data-item-keys={entries.flatMap((entry) => (entry.type === 'event' ? [entry.item.key] : [])).join(',')}
                />
                {notice && <ThreadNotice notice={notice} threadId={threadId} readonly={readonly} />}
            </div>
            <MediaLightbox media={lightboxMedia} index={lightboxIndex} onOpenChange={(open) => !open && setLightboxMedia(null)} onIndexChange={setLightboxIndex} />
        </div>
    );
}
