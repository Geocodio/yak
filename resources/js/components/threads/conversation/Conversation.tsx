import { Fragment } from 'react';
import type { ThreadMessage, ThreadNotice as ThreadNoticeData } from '@/types/threads';
import { ClaudeMessage } from './ClaudeMessage';
import { DayDivider } from './DayDivider';
import { EditsBlock } from './EditsBlock';
import { mergeConversation, useConversation } from './useConversation';
import { PersonMessage } from './PersonMessage';
import { ThreadNotice } from './ThreadNotice';
import { ToolActivity } from './ToolActivity';
import type { ConversationItem } from './realtimeStub';

// Browser tests drive the pure merge through this hook.
(window as unknown as { __mergeConversation?: typeof mergeConversation }).__mergeConversation = mergeConversation;

function EventRow({ item }: { item: ConversationItem }) {
    if (item.kind === 'tools') {
        return <ToolActivity item={item} />;
    }

    return item.kind === 'edits' ? <EditsBlock item={item} /> : <ClaudeMessage item={item} />;
}

export function Conversation({ threadId, messages, notice, readonly }: { threadId: number; messages: ThreadMessage[]; notice: ThreadNoticeData | null; readonly: boolean }) {
    const { entries } = useConversation(threadId, messages);
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
                            <PersonMessage message={entry.message} pending={entry.pending} />
                        </Fragment>
                    );
                })}
                {notice && <ThreadNotice notice={notice} threadId={threadId} readonly={readonly} />}
            </div>
        </div>
    );
}
