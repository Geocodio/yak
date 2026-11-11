import { useMemo, useRef } from 'react';
import type { ThreadMessage } from '@/types/threads';
import { createReadTracker, mergeConversation, type ConversationEntry } from './mergeConversation';
import { useThreadEvents } from './realtimeStub';

export { mergeConversation, type ConversationEntry };

export function useConversation(threadId: number, messages: ThreadMessage[]): { entries: ConversationEntry[] } {
    const events = useThreadEvents(threadId);
    const track = useRef(createReadTracker()).current;
    const readUuids = track(events.readUuids);
    const readKey = readUuids.join(',');
    const entries = useMemo(() => mergeConversation(messages, readUuids, events.items), [messages, readKey, events.items]);

    return { entries };
}
