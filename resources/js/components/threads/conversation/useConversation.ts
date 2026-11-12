import { useMemo, useRef } from 'react';
import { useThreadEventFeed } from '@/threads/realtime/useThreadEventFeed';
import { livePropsSignature, useRefreshThreadProps } from '@/threads/realtime/useRefreshThreadProps';
import type { ThreadMessage } from '@/types/threads';
import { projectConversation } from './projectConversation';
import { createReadTracker, mergeConversation, type ConversationEntry } from './mergeConversation';

export { mergeConversation, type ConversationEntry };

export function useConversation(threadId: number, messages: ThreadMessage[]): { entries: ConversationEntry[]; lastSequence: number; connection: string } {
    const { state: events, entrySequences } = useThreadEventFeed(threadId);
    useRefreshThreadProps(livePropsSignature(threadId, events));
    const track = useRef(createReadTracker()).current;
    const { items, readSequences } = useMemo(() => projectConversation(events, entrySequences), [events.entries, entrySequences, events.streamingBlocks, events.children, events.repositories, events.lastSequence]);
    const readUuids = track(Object.keys(readSequences));
    const readKey = readUuids.join(',');
    const entries = useMemo(() => mergeConversation(messages, readUuids, items, readSequences), [messages, readKey, items, readSequences]);

    return { entries, lastSequence: events.lastSequence, connection: events.connection };
}
