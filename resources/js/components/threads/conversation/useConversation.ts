import { useMemo, useRef } from 'react';
import { useThreadEventFeed } from '@/threads/realtime/useThreadEventFeed';
import { livePropsSignature, useRefreshThreadProps } from '@/threads/realtime/useRefreshThreadProps';
import type { LinkState } from '@/threads/realtime/catchUpRunner';
import type { ThreadEventState } from '@/types/thread-events';
import type { ThreadMessage } from '@/types/threads';
import { projectConversation } from './projectConversation';
import { liveStepCount } from '@/threads/liveSteps';
import { createReadTracker, mergeConversation, type ConversationEntry } from './mergeConversation';

export { mergeConversation, type ConversationEntry };

export function useConversation(threadId: string, messages: ThreadMessage[], turnStartSequence = 0): { entries: ConversationEntry[]; liveSteps: number; lastSequence: number; connection: string; link: LinkState; agents: ThreadEventState['agents'] } {
    const { state: events, entrySequences, entryTimes, link } = useThreadEventFeed(threadId);
    useRefreshThreadProps(livePropsSignature(threadId, events));
    const track = useRef(createReadTracker()).current;
    const { items, readSequences } = useMemo(() => projectConversation(events, entrySequences, entryTimes), [events.entries, entrySequences, entryTimes, events.streamingBlocks, events.children, events.repositories, events.lastSequence]);
    const readUuids = track(Object.keys(readSequences));
    const readKey = readUuids.join(',');
    const entries = useMemo(() => mergeConversation(messages, readUuids, items, readSequences), [messages, readKey, items, readSequences]);

    const liveSteps = useMemo(() => liveStepCount(events.entries, entrySequences, turnStartSequence), [events.entries, entrySequences, turnStartSequence]);

    return { entries, liveSteps, lastSequence: events.lastSequence, connection: events.connection, link, agents: events.agents };
}
