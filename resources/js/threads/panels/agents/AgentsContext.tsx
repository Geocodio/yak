import { router } from '@inertiajs/react';
import { createContext, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { hasMutationsInFlight } from '@/threads/realtime/useRefreshThreadProps';
import type { ThreadAgent, ThreadAgentsData } from '@/types/thread-agents';
import { countRunning, mergeAgents, type AgentRowData } from './agentsState';

export type AgentsContextValue = {
    threadId: string;
    canDrive: boolean;
    /** Every agent and workflow of the thread: the page's rows with the live rows over them. */
    rows: AgentRowData[];
    main: ThreadAgentsData['main'];
    subThreads: ThreadAgentsData['subThreads'];
    running: { agents: number; workflows: number };
    /** The clock the running times read; it ticks every second while anything runs. */
    now: number;
    selectedId: string | null;
    select: (id: string | null) => void;
    /** Opens the Agents panel, on one agent when an id is given. */
    openPanel: (agentId?: string) => void;
    /** Opens the full log of one agent in the log overlay. */
    openFullLog: (agent: ThreadAgent) => void;
};

const AgentsContext = createContext<AgentsContextValue | null>(null);

const EMPTY: ThreadAgentsData = { main: { state: 'idle', stepsThisTurn: null, runningAgents: 0 }, agents: [], subThreads: [], runningCount: 0 };

/** The agents of the thread, or null outside a thread page. */
export function useAgents(): AgentsContextValue | null {
    return useContext(AgentsContext);
}

const RELOAD_SETTLE_MS = 400;

/** Reads the agents prop again whenever an agent starts, finishes or is asked to stop, so messages and sub-threads stay current. */
function useReloadAgents(signature: string, hasUnforwardedMessage: boolean): void {
    const seen = useRef(signature);

    useEffect(() => {
        if (seen.current === signature) {
            return;
        }

        seen.current = signature;
        let timer = window.setTimeout(reload, RELOAD_SETTLE_MS);

        function reload(): void {
            if (hasMutationsInFlight()) {
                timer = window.setTimeout(reload, RELOAD_SETTLE_MS);

                return;
            }

            router.reload({ only: ['agents'], showProgress: false, onHttpException: () => false, onNetworkError: () => false });
        }

        return () => window.clearTimeout(timer);
    }, [signature]);

    // A message waits for Claude to pass it on; its row changes on the server only, so it is read again until then.
    useEffect(() => {
        if (!hasUnforwardedMessage) {
            return;
        }

        const interval = window.setInterval(() => {
            if (!hasMutationsInFlight()) {
                router.reload({ only: ['agents'], showProgress: false, onHttpException: () => false, onNetworkError: () => false });
            }
        }, 4000);

        return () => window.clearInterval(interval);
    }, [hasUnforwardedMessage]);
}

export function AgentsProvider({
    threadId,
    canDrive,
    loaded = EMPTY,
    live,
    selectedId,
    onSelect,
    openPanel,
    openFullLog,
    children,
}: {
    threadId: string;
    canDrive: boolean;
    loaded?: ThreadAgentsData;
    live: Record<string, ThreadAgent>;
    selectedId: string | null;
    onSelect: (id: string | null) => void;
    openPanel: (agentId?: string) => void;
    openFullLog: (agent: ThreadAgent) => void;
    children: ReactNode;
}) {
    const rows = useMemo(() => mergeAgents(loaded.agents, live), [loaded.agents, live]);
    const running = useMemo(() => countRunning(rows), [rows]);
    const isRunningAny = running.agents + running.workflows > 0;
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        if (!isRunningAny) {
            return;
        }

        setNow(Date.now());
        const interval = window.setInterval(() => setNow(Date.now()), 1000);

        return () => window.clearInterval(interval);
    }, [isRunningAny]);

    const signature = rows.map((agent) => `${agent.id}:${agent.status}:${agent.stopRequestedAt ?? ''}:${agent.followUpTurnId ?? ''}`).join('|');
    useReloadAgents(`${threadId}|${signature}`, rows.some((agent) => agent.status === 'running' && agent.messages.some((message) => message.forwardedAt === null)));

    const value = useMemo<AgentsContextValue>(
        () => ({ threadId, canDrive, rows, main: loaded.main, subThreads: loaded.subThreads, running, now, selectedId, select: onSelect, openPanel, openFullLog }),
        [threadId, canDrive, rows, loaded.main, loaded.subThreads, running, now, selectedId, onSelect, openPanel, openFullLog],
    );

    return <AgentsContext.Provider value={value}>{children}</AgentsContext.Provider>;
}
