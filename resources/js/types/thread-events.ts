import type { ThreadAgent } from '@/types/thread-agents';

export type CutReason = 'stopped' | 'restarted';

/** Mirrors `App\Enums\ThreadStatus`. */
export type ThreadStatus = 'open' | 'closed';

/** Mirrors `App\Enums\TurnState`. */
export type TurnState =
    | 'pending'
    | 'waiting_for_account'
    | 'waiting_for_sandbox'
    | 'running'
    | 'completed'
    | 'failed'
    | 'stopped_by_guard'
    | 'interrupted';

export type AssistantBlock =
    | { type: 'text'; text: string; html?: string }
    | { type: 'thinking'; text: string }
    | { type: 'tool_use'; id: string; name: string; input: Record<string, unknown> };

export type TranscriptEntry = (
    | { role: 'assistant'; messageId: string; blocks: AssistantBlock[]; blockIndex?: number; startedAt?: string; cutOff?: boolean; cutReason?: CutReason }
    | { role: 'tool_result'; toolUseId: string; isError: boolean; text: string; truncated?: boolean }
    | { role: 'system'; subtype: 'init'; model?: string; sessionId?: string }
    | { role: 'user'; uuid?: string; text: string; kind: 'prompt' | 'steer' }
    | {
          role: 'result';
          subtype: string;
          isError: boolean;
          text: string;
          html?: string;
          costUsd: number;
          durationMs: number;
          numTurns: number;
      }
) & {
    /** Set on a line an agent wrote; Claude's own transcript leaves these out and the agent's log shows them. */
    agentId?: string;
};

export type ChildCardState = 'starting' | 'queued' | 'working' | 'waiting' | 'done' | 'failed' | 'closed';

/** The latest state of one sub-thread, as the parent's conversation shows it. */
export type ChildCardData = {
    childThreadId: string;
    name: string;
    kind: string;
    model: string | null;
    state: ChildCardState;
    step: string | null;
    resultSummary: string | null;
    branch: string | null;
    messagesPaused: boolean;
    mergedAt: string | null;
    question: string | null;
    filesChanged: number | null;
    additions: number | null;
    deletions: number | null;
    conflictResolved: boolean;
    decision: string | null;
};

/** A sub-thread's result or failure, delivered to this thread at the point it appears in the conversation. */
export type ChildResultData = {
    id: number;
    childThreadId: string;
    title: string;
    kind: string;
    state: 'done' | 'failed';
    reason: string | null;
    html: string;
    text: string;
    /** When the event was recorded; set by the reducer from the envelope. */
    at: string;
};

export type RepositoryCardState = 'running' | 'setup' | 'done' | 'setup_failed' | 'no_access';

/** The latest state of one repository Claude added, as the conversation shows it. */
export type RepositoryCardData = {
    /** The thread repository row, or null when the clone was denied before one existed. */
    id: number | null;
    repo: string;
    role: 'changes' | 'context';
    state: RepositoryCardState;
    path: string | null;
    reason: string | null;
    cloneSeconds: number | null;
    setupSeconds: number | null;
    output: string | null;
    error: string | null;
};

export type ThreadEventType = 'entry' | 'delta' | 'block.cut' | 'stream.reset' | 'turn' | 'thread' | 'message.state' | 'diff.updated' | 'child' | 'child.result' | 'repository' | 'question' | 'agent.started' | 'agent.progress' | 'agent.finished';

export type ThreadEventDataMap = {
    entry: TranscriptEntry;
    delta: { blockKey: string; kind: 'text' | 'thinking'; text: string; offset: number; startedAt?: string };
    'block.cut': { blockKey: string; cutReason?: CutReason };
    'stream.reset': Record<string, never>;
    turn: { turnId: number; state: TurnState };
    thread: { status: ThreadStatus; sandboxState: string; runnerState: string; blockedReason: string | null; pullRequestOpening?: 'opening' | 'failed' | null; ownerSide?: 'yak' | 'device'; showKey?: string };
    'message.state': { uuid: string; state: 'sent' | 'queued' | 'started' | 'completed' | 'cancelled' };
    'diff.updated': Record<string, never>;
    child: ChildCardData;
    'child.result': Omit<ChildResultData, 'at'>;
    repository: RepositoryCardData;
    question: { open: boolean };
    'agent.started': ThreadAgent;
    'agent.progress': ThreadAgent;
    'agent.finished': ThreadAgent;
};

export type ThreadEventEnvelope<T extends ThreadEventType = ThreadEventType> = {
    sequence: number;
    type: T;
    data: ThreadEventDataMap[T];
    createdAt: string;
};

/** Sent instead of the envelope when it would exceed the broadcast size cap; fetch it over HTTP. */
export type ThreadEventPointer = { sequence: number; pointer: true };

export type Viewer = { id: number; name: string };

export type ThreadEventState = {
    entries: TranscriptEntry[];
    streamingBlocks: Record<string, { kind: string; text: string; seen: boolean; startedAt: string }>;
    turn: { turnId: number; state: TurnState } | null;
    /** Sequence of the event that started the current turn running; null when no such event is in the feed. */
    turnStartSequence: number | null;
    thread: ThreadEventDataMap['thread'] | null;
    /** Whether the head task has questions open, from the latest `question` event; null before one arrives. */
    questionOpen: boolean | null;
    /** Latest event per sub-thread, with the sequence of its first event. */
    children: Record<string, { sequence: number; data: ChildCardData }>;
    /** Every delivered sub-thread result, in order, each with the sequence it arrived at. */
    childResults: { sequence: number; data: ChildResultData }[];
    /** Latest event per added repository, keyed by slug, with the sequence of its first event. */
    repositories: Record<string, { sequence: number; data: RepositoryCardData }>;
    /** Latest state of each agent or workflow by task id, from the `agent.*` events. */
    agents: Record<string, ThreadAgent>;
    viewers: Viewer[];
    connection: 'connecting' | 'live' | 'catching-up' | 'offline';
    lastSequence: number;
};
