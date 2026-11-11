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

export type ThreadEventType = 'entry' | 'delta' | 'stream.reset' | 'turn' | 'thread' | 'message.state' | 'diff.updated';

export type ThreadEventDataMap = {
    entry: TranscriptEntry;
    delta: { blockKey: string; kind: 'text' | 'thinking' | 'tool_input'; text: string };
    'stream.reset': Record<string, never>;
    turn: { turnId: number; state: TurnState };
    thread: { status: ThreadStatus; sandboxState: string; runnerState: string; blockedReason: string | null };
    'message.state': { uuid: string; state: 'sent' | 'queued' | 'started' | 'completed' | 'cancelled' };
    'diff.updated': Record<string, never>;
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
    viewers: Viewer[];
    connection: 'connecting' | 'live' | 'catching-up' | 'offline';
    lastSequence: number;
};
