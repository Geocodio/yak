import type { ThreadAgent, ThreadAgentMessage } from '@/types/thread-agents';

export type AgentRowData = ThreadAgent & { messages: ThreadAgentMessage[] };

export type AgentNode = { agent: AgentRowData; children: AgentNode[] };

/** One row of the flattened tree: how far it is indented under Claude, and who started it. */
export type AgentListRow = { agent: AgentRowData; depth: number; parent: AgentRowData | null };

/**
 * The rows the page was loaded with, with the live rows of the `agent.*` events laid over them. A live row carries no
 * messages, so the page's messages stay; an agent only the live feed knows follows the loaded ones.
 */
export function mergeAgents(loaded: AgentRowData[], live: Record<string, ThreadAgent>): AgentRowData[] {
    const known = new Set(loaded.map((agent) => agent.id));
    const merged = loaded.map((agent) => (live[agent.id] === undefined ? agent : { ...agent, ...live[agent.id], messages: agent.messages }));
    const added = Object.values(live)
        .filter((agent) => !known.has(agent.id))
        .sort((a, b) => (a.startedAt ?? '').localeCompare(b.startedAt ?? ''))
        .map((agent) => ({ ...agent, messages: [] }));

    return [...merged, ...added];
}

export const isRunning = (agent: ThreadAgent): boolean => agent.status === 'running';

/** Running agents come first; each group keeps the order the agents started in. */
function runningFirst(nodes: AgentNode[]): AgentNode[] {
    return [...nodes.filter((node) => isRunning(node.agent)), ...nodes.filter((node) => !isRunning(node.agent))];
}

/** Nests the flat rows by `parentTaskId`. An agent whose parent is not known is started by Claude. */
export function buildTree(rows: AgentRowData[]): AgentNode[] {
    const nodes = new Map(rows.map((agent) => [agent.id, { agent, children: [] as AgentNode[] }]));
    const roots: AgentNode[] = [];

    rows.forEach((agent) => {
        const node = nodes.get(agent.id)!;
        const parent = agent.parentTaskId === null || agent.parentTaskId === agent.id ? undefined : nodes.get(agent.parentTaskId);

        (parent === undefined ? roots : parent.children).push(node);
    });

    const order = (list: AgentNode[]): AgentNode[] => runningFirst(list).map((node) => ({ ...node, children: order(node.children) }));

    return order(roots);
}

/** The tree in reading order; depth 0 is an agent Claude started. */
export function flattenTree(nodes: AgentNode[], depth = 0, parent: AgentRowData | null = null): AgentListRow[] {
    return nodes.flatMap((node) => [{ agent: node.agent, depth, parent }, ...flattenTree(node.children, depth + 1, node.agent)]);
}

export function countRunning(rows: ThreadAgent[]): { agents: number; workflows: number } {
    const running = rows.filter(isRunning);

    return { agents: running.filter((agent) => agent.kind === 'agent').length, workflows: running.filter((agent) => agent.kind === 'workflow').length };
}

/** "2 agents and 1 workflow running"; null when nothing runs. */
export function runningSummary({ agents, workflows }: { agents: number; workflows: number }): string | null {
    const parts = [agents > 0 ? `${agents} ${agents === 1 ? 'agent' : 'agents'}` : null, workflows > 0 ? `${workflows} ${workflows === 1 ? 'workflow' : 'workflows'}` : null].filter((part) => part !== null);

    return parts.length === 0 ? null : `${parts.join(' and ')} running`;
}

/** "6m 12s", "1m 02s", "28s", "1h 05m". */
export function formatAgentDuration(milliseconds: number): string {
    const seconds = Math.max(0, Math.round(milliseconds / 1000));

    if (seconds < 60) {
        return `${seconds}s`;
    }

    if (seconds < 3600) {
        return `${Math.floor(seconds / 60)}m ${String(seconds % 60).padStart(2, '0')}s`;
    }

    return `${Math.floor(seconds / 3600)}h ${String(Math.floor((seconds % 3600) / 60)).padStart(2, '0')}m`;
}

/** How long the agent has worked: live while it runs, the recorded time once it has finished. */
export function agentDurationMs(agent: ThreadAgent, now: number): number {
    if (isRunning(agent) && agent.startedAt !== null) {
        return Math.max(agent.durationMs, now - Date.parse(agent.startedAt));
    }

    if (agent.durationMs > 0 || agent.startedAt === null || agent.finishedAt === null) {
        return agent.durationMs;
    }

    return Date.parse(agent.finishedAt) - Date.parse(agent.startedAt);
}

/** "41k tokens"; below ten thousand one decimal keeps small counts honest. */
export function formatTokens(tokens: number): string {
    if (tokens < 1000) {
        return `${tokens} tokens`;
    }

    const thousands = tokens / 1000;

    return `${thousands < 10 ? Math.round(thousands * 10) / 10 : Math.round(thousands)}k tokens`;
}

const FILE_TOOLS = new Set(['Read', 'Write', 'Edit', 'NotebookEdit']);

/** What a running agent is doing: "Write crm-pipeline.html" (a file shows by its name), or just the tool; null when unknown. */
export function nowLabel(agent: ThreadAgent): string | null {
    if (agent.lastTool === null || agent.lastTool === '') {
        return null;
    }

    const target = agent.lastToolTarget === null || agent.lastToolTarget === '' ? null : FILE_TOOLS.has(agent.lastTool) ? (agent.lastToolTarget.split('/').pop() ?? agent.lastToolTarget) : agent.lastToolTarget;

    return target === null ? agent.lastTool : `${agent.lastTool} ${target}`;
}

/**
 * The agent a log step started: by the tool call id of the step's Agent call, and only when the step carries no ids by
 * description, then by being the next one not shown yet.
 */
export function matchChild(entry: { subject?: string; agentToolUseIds?: string[] }, children: AgentRowData[], used: Set<string>): AgentRowData | undefined {
    const free = children.filter((child) => !used.has(child.id));
    const ids = entry.agentToolUseIds ?? [];

    if (ids.length > 0) {
        return free.find((child) => child.toolUseId !== null && ids.includes(child.toolUseId));
    }

    return free.find((child) => child.description === entry.subject || child.workflowName === entry.subject) ?? free[0];
}

export function agentTitle(agent: ThreadAgent): string {
    return agent.kind === 'workflow' ? `Workflow: ${agent.workflowName ?? agent.description ?? 'workflow'}` : (agent.description ?? agent.agentType ?? 'Agent');
}

const STATUS_WORD = { running: 'Running', completed: 'Finished', failed: 'Failed', stopped: 'Stopped' } as const;

/** The status with its time: "Running 6m 12s", "Finished after 9m 04s". */
export function statusLine(agent: ThreadAgent, now: number): string {
    const duration = formatAgentDuration(agentDurationMs(agent, now));

    return isRunning(agent) ? `Running ${duration}` : `${STATUS_WORD[agent.status]} after ${duration}`;
}

export type WorkflowPhase = { title: string; state: 'done' | 'running' | 'waiting'; label: string };

const OPEN_STATES = new Set(['start', 'queued', 'running']);

/** The phase tiles of a workflow, from its `workflow_progress`. */
export function workflowPhases(progress: Record<string, unknown>[] | null): WorkflowPhase[] {
    const phases = (progress ?? []).filter((item) => item.type === 'workflow_phase').map((item) => ({ index: Number(item.index), title: String(item.title ?? `Phase ${item.index}`) }));
    const members = (progress ?? []).filter((item) => item.type === 'workflow_agent');
    const lastStarted = Math.max(0, ...members.map((member) => Number(member.phaseIndex)));

    return phases.map((phase): WorkflowPhase => {
        const inPhase = members.filter((member) => Number(member.phaseIndex) === phase.index);
        const open = inPhase.filter((member) => OPEN_STATES.has(String(member.state)));
        const done = inPhase.length - open.length;

        if (inPhase.length === 0) {
            return phase.index < lastStarted ? { title: phase.title, state: 'done', label: 'done' } : { title: phase.title, state: 'waiting', label: 'waiting' };
        }

        if (open.length === 0) {
            return { title: phase.title, state: 'done', label: 'done' };
        }

        return { title: phase.title, state: 'running', label: done === 0 ? `${open.length} ${open.length === 1 ? 'agent' : 'agents'} running` : `${done} of ${inPhase.length} agents` };
    });
}

/** How many agents a workflow has run so far. */
export function workflowAgentCount(progress: Record<string, unknown>[] | null): number {
    return (progress ?? []).filter((item) => item.type === 'workflow_agent').length;
}

const plural = (count: number, word: string): string => `${count} ${word}${count === 1 ? '' : 's'}`;

/** The subtitle of a card: type, background or foreground, status, and what it is doing or has used. */
export function cardSubtitle(agent: ThreadAgent, now: number, isExpanded: boolean): string {
    const count = workflowAgentCount(agent.workflowProgress);
    const kindParts = agent.kind === 'workflow' ? [count > 0 ? plural(count, 'agent') : 'workflow'] : [agent.agentType ?? 'agent', agent.isBackground ? 'background' : 'foreground'];
    const nowText = nowLabel(agent);
    const activity = isRunning(agent) && !isExpanded && nowText !== null ? [`now: ${nowText}`] : [agent.totalTokens > 0 ? formatTokens(agent.totalTokens) : null, agent.toolUses > 0 && agent.kind === 'agent' ? plural(agent.toolUses, 'tool use') : null].filter((part) => part !== null);

    return [...kindParts, statusLine(agent, now), ...activity].join(' · ');
}

/** The subtitle of a panel row: kind, who started it and how it is going. */
export function rowSubtitle(agent: ThreadAgent, parent: AgentRowData | null, now: number): string {
    const kind = agent.kind === 'workflow' ? 'Workflow' : 'Agent';
    const startedBy = `started by ${parent === null ? 'Claude' : agentTitle(parent)}`;
    const duration = formatAgentDuration(agentDurationMs(agent, now));

    if (isRunning(agent)) {
        const count = workflowAgentCount(agent.workflowProgress);
        const doing = agent.kind === 'workflow' ? (count > 0 ? plural(count, 'agent') : null) : nowLabel(agent);

        return [kind, startedBy, duration, doing].filter((part) => part !== null && part !== '').join(' · ');
    }

    const outcome = agent.status === 'completed' ? 'finished' : agent.status;

    return [kind, outcome, duration, agent.totalTokens > 0 ? formatTokens(agent.totalTokens) : null, startedBy].filter((part) => part !== null).join(' · ');
}

/** The agents an agent started, anywhere below it. */
export function descendantCount(rows: AgentRowData[], agent: ThreadAgent): number {
    const direct = rows.filter((row) => row.parentTaskId === agent.id);

    return direct.length + direct.reduce((total, row) => total + descendantCount(rows, row), 0);
}

/** The number beside "Agents": what is running, or everything the thread has started once nothing runs. */
export function agentsTabCount(rows: ThreadAgent[], subThreadCount: number): number {
    const running = rows.filter(isRunning).length;

    return running > 0 ? running : rows.length + subThreadCount;
}

/** The agents whose end made Claude take a turn, by the run that turn started. */
export function continuedByRun<T extends ThreadAgent>(rows: T[]): Map<number, T[]> {
    const byRun = new Map<number, T[]>();

    rows.forEach((agent) => {
        if (agent.followUpTurnId !== null) {
            byRun.set(agent.followUpTurnId, [...(byRun.get(agent.followUpTurnId) ?? []), agent]);
        }
    });

    return byRun;
}

/** One string that changes when a sub-thread of the thread appears or changes state, so the Agents tab reads its sub-threads again. */
export function childrenSignature(children: Record<string, { data: { state: string } }>): string {
    return Object.entries(children)
        .map(([id, child]) => `${id}:${child.data.state}`)
        .sort()
        .join('|');
}

/**
 * The state a sub-thread row or card shows. The server never reports a research sub-thread as waiting for a free
 * spot (it shares this thread's sandbox), so an older stored card that says so reads as Starting.
 */
export function childDisplayState<S extends string>(state: S, kind: string | undefined): S | 'starting' {
    return state === 'queued' && kind === 'research' ? 'starting' : state;
}

/**
 * The live card of each sub-thread laid over the rows a prop loaded: the card's events arrive before the props reload,
 * so every surface that lists sub-threads (the sidebar group, the Agents tab) shows the state the conversation shows.
 * Card, stack and Agents rows share one state vocabulary.
 */
export function overlayChildren<T extends { id: string; state: string; step: string | null; kind?: string }>(
    rows: T[],
    live: Record<string, { data: { state: string; step: string | null } }>,
): T[] {
    if (Object.keys(live).length === 0) {
        return rows;
    }

    return rows.map((row) => {
        const card = live[row.id]?.data;

        return card === undefined ? row : { ...row, state: childDisplayState(card.state, row.kind), step: card.step };
    });
}

/** The agents of one merged follow-up run: in the order they finished, and how the row names them. */
export function continuedParts(agents: AgentRowData[]): { named: AgentRowData[]; more: number; verb: string; after: string | null } {
    const ordered = [...agents].sort((a, b) => (a.finishedAt ?? '').localeCompare(b.finishedAt ?? '') || a.id.localeCompare(b.id));
    const statuses = new Set(ordered.map((agent) => agent.status));
    const verb = statuses.size === 1 ? (ordered[0].status === 'completed' ? 'finished' : ordered[0].status) : 'ended';
    const named = ordered.length <= 2 ? ordered : ordered.slice(0, 2);

    return { named, more: ordered.length - named.length, verb, after: ordered.length === 1 ? formatAgentDuration(agentDurationMs(ordered[0], 0)) : null };
}

/** "X finished after 39s.", "X and Y finished.", "X, Y and 2 more finished." */
export function continuedLabel(agents: AgentRowData[]): string {
    const { named, more, verb, after } = continuedParts(agents);
    const names = named.map(agentTitle);
    const who = more > 0 ? `${names.join(', ')} and ${more} more` : names.join(' and ');

    return `${who} ${verb}${after === null ? '' : ` after ${after}`}.`;
}
