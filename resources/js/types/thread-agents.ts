/** Mirrors the array shapes in `App\Http\Resources\ThreadAgentsData`. */

export type ThreadAgentStatus = 'running' | 'completed' | 'failed' | 'stopped';

/** One agent or workflow Claude started in the thread; the payload of the `agent.started`, `agent.progress` and `agent.finished` events. */
export type ThreadAgent = {
    /** Claude's task id; the id Yak stops the agent by. */
    id: string;
    kind: 'agent' | 'workflow';
    parentTaskId: string | null;
    toolUseId: string | null;
    depth: number;
    agentType: string | null;
    description: string | null;
    workflowName: string | null;
    model: string | null;
    isBackground: boolean;
    status: ThreadAgentStatus;
    lastTool: string | null;
    summary: string | null;
    totalTokens: number;
    toolUses: number;
    durationMs: number;
    startedAt: string | null;
    finishedAt: string | null;
    stopRequestedAt: string | null;
    stopError: string | null;
    workflowProgress: Record<string, unknown>[] | null;
    startedByTurnId: number | null;
    followUpTurnId: number | null;
};

export type ThreadAgentMessage = {
    id: number;
    text: string;
    authorName: string | null;
    sentAt: string;
    forwardedAt: string | null;
};

export type ThreadSubThread = {
    id: string;
    name: string;
    title: string;
    kind: string;
    state: 'queued' | 'working' | 'waiting' | 'done' | 'failed' | 'closed';
    step: string | null;
    branch: string | null;
    pullRequestUrl: string | null;
};

/** The `agents` prop of `Threads/Show`. */
export type ThreadAgentsData = {
    /** Claude itself, the root of the tree. `waiting` means only agents run. */
    main: { state: 'working' | 'waiting' | 'idle'; stepsThisTurn: number | null; runningAgents: number };
    agents: (ThreadAgent & { messages: ThreadAgentMessage[] })[];
    subThreads: ThreadSubThread[];
    runningCount: number;
};

/** One page of an agent's own log, in the shape of the run log entries. */
export type ThreadAgentLogPage = { entries: Record<string, unknown>[]; nextCursor: number | null };
