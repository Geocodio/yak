import { useEffect, useRef } from 'react';
import { useAgents } from '@/threads/panels/agents/AgentsContext';
import { agentsForApp } from '@/threads/panels/agents/agentsState';
import { useEmbed } from './useEmbed';

/** The app's bar is told once the set has been steady this long, so a burst of agent events is one message. */
const SETTLE_MS = 300;

/**
 * Tells the app how many agents run and which card is the newest, whenever that changes. The app has no second source
 * of truth: a page that loads with nothing running says nothing, and a thread switch starts from nothing again.
 * Draws nothing; renders inside the agents provider of the thread page.
 */
export function EmbedAgentsReporter() {
    const embed = useEmbed();
    const agents = useAgents();
    const summary = agentsForApp(agents?.rows ?? []);
    const threadId = agents?.threadId ?? null;
    const sent = useRef<{ threadId: string | null; running: number; newest: string | null }>({ threadId: null, running: 0, newest: null });

    useEffect(() => {
        if (embed === null || threadId === null) {
            return;
        }
        if (sent.current.threadId !== threadId) {
            sent.current = { threadId, running: 0, newest: null };
        }
        if (sent.current.running === summary.running && sent.current.newest === summary.newest) {
            return;
        }
        const timer = window.setTimeout(() => {
            sent.current = { threadId, ...summary };
            embed.post({ type: 'agents', ...summary });
        }, SETTLE_MS);

        return () => window.clearTimeout(timer);
    }, [embed, threadId, summary.running, summary.newest]);

    return null;
}
