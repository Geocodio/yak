import { useEffect, useMemo, useRef } from 'react';
import { reportFeedProgress } from '@/embed/feedProgress';
import { useEmbed } from '@/embed/useEmbed';
import { useThreadEventFeed } from '@/threads/realtime/useThreadEventFeed';
import { livePropsSignature, useRefreshThreadProps } from '@/threads/realtime/useRefreshThreadProps';
import type { LinkState } from '@/threads/realtime/catchUpRunner';
import type { ThreadEventState } from '@/types/thread-events';
import type { ThreadMessage } from '@/types/threads';
import { projectConversation } from './projectConversation';
import { liveStepCount } from '@/threads/liveSteps';
import { createReadTracker, mergeConversation, type ConversationEntry } from './mergeConversation';

export { mergeConversation, type ConversationEntry };

export function useConversation(threadId: string, messages: ThreadMessage[], fallbackTurnStart = 0): { entries: ConversationEntry[]; liveSteps: number; lastSequence: number; connection: string; link: LinkState; agents: ThreadEventState['agents']; children: ThreadEventState['children'] } {
    const { state: events, entrySequences, entryTimes, link } = useThreadEventFeed(threadId, useEmbed() === null);
    useEffect(() => reportFeedProgress({ threadId, lastSequence: events.lastSequence, isSettled: events.connection === 'live' || events.connection === 'offline' }), [threadId, events.lastSequence, events.connection]);
    useRefreshThreadProps(livePropsSignature(threadId, events));
    const track = useRef(createReadTracker()).current;
    const { items, readSequences } = useMemo(() => projectConversation(events, entrySequences, entryTimes), [events.entries, entrySequences, entryTimes, events.streamingBlocks, events.children, events.repositories, events.lastSequence]);
    const readUuids = track(Object.keys(readSequences));
    const readKey = readUuids.join(',');
    const entries = useMemo(() => mergeConversation(messages, readUuids, items, readSequences), [messages, readKey, items, readSequences]);

    const liveSteps = useMemo(() => liveStepCount(events.entries, entrySequences, events.turnStartSequence ?? fallbackTurnStart), [events.entries, entrySequences, events.turnStartSequence, fallbackTurnStart]);

    return { entries, liveSteps, lastSequence: events.lastSequence, connection: events.connection, link, agents: events.agents, children: events.children };
}
