import { useEffect, useRef } from 'react';
import { useAgents } from '@/threads/panels/agents/AgentsContext';
import { agentsForApp, needsAgentsReport } from '@/threads/panels/agents/agentsState';
import { useEmbed } from './useEmbed';

/** The app's bar is told once the set has been steady this long, so a burst of agent events is one message. */
const SETTLE_MS = 300;

/**
 * Tells the app how many agents run and which card is the newest: once when the thread page mounts and after a thread
 * switch (also when nothing runs, so the app's bar never keeps a stale count after a reload), then whenever the pair
 * changes. Draws nothing; renders inside the agents provider of the thread page.
 */
export function EmbedAgentsReporter() {
    const embed = useEmbed();
    const agents = useAgents();
    const summary = agentsForApp(agents?.rows ?? []);
    const threadId = agents?.threadId ?? null;
    const sent = useRef<{ threadId: string; running: number; newest: string | null } | null>(null);

    useEffect(() => {
        if (embed === null || threadId === null || !needsAgentsReport(sent.current, threadId, summary)) {
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
