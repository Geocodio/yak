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
    | { type: 'text'; text: string }
    | { type: 'thinking'; text: string }
    | { type: 'tool_use'; id: string; name: string; input: Record<string, unknown> };

export type TranscriptEntry =
    | { role: 'assistant'; messageId: string; blocks: AssistantBlock[] }
    | { role: 'tool_result'; toolUseId: string; isError: boolean; text: string; truncated?: boolean }
    | { role: 'system'; subtype: 'init'; model?: string; sessionId?: string }
    | { role: 'user'; uuid?: string; text: string; kind: 'prompt' | 'steer' }
    | {
          role: 'result';
          subtype: string;
          isError: boolean;
          text: string;
          costUsd: number;
          durationMs: number;
          numTurns: number;
      };

export type ChildCardState = 'queued' | 'working' | 'waiting' | 'done' | 'failed' | 'closed';

/** The latest state of one sub-thread, as the parent's conversation shows it. */
export type ChildCardData = {
    childThreadId: number;
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

export type ThreadEventType = 'entry' | 'delta' | 'stream.reset' | 'turn' | 'thread' | 'message.state' | 'diff.updated' | 'child' | 'repository';

export type ThreadEventDataMap = {
    entry: TranscriptEntry;
    delta: { blockKey: string; kind: 'text' | 'thinking' | 'tool_input'; text: string };
    'stream.reset': Record<string, never>;
    turn: { turnId: number; state: TurnState };
    thread: { status: ThreadStatus; sandboxState: string; runnerState: string; blockedReason: string | null };
    'message.state': { uuid: string; state: 'sent' | 'queued' | 'started' | 'completed' | 'cancelled' };
    'diff.updated': Record<string, never>;
    child: ChildCardData;
    repository: RepositoryCardData;
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
    streamingBlocks: Record<string, { kind: string; text: string }>;
    turn: { turnId: number; state: TurnState } | null;
    thread: ThreadEventDataMap['thread'] | null;
    /** Latest event per sub-thread, with the sequence of its first event. */
    children: Record<number, { sequence: number; data: ChildCardData }>;
    /** Latest event per added repository, keyed by slug, with the sequence of its first event. */
    repositories: Record<string, { sequence: number; data: RepositoryCardData }>;
    viewers: Viewer[];
    connection: 'connecting' | 'live' | 'catching-up' | 'offline';
    lastSequence: number;
};
