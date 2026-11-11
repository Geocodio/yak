/**
 * Local mirror of W2-REALTIME's `useThreadEvents`. When that track merges, the
 * import in `useConversation.ts` switches to `@/threads/realtime` and this file goes.
 */
export type ConversationItem = {
    kind: 'text' | 'thinking' | 'tools' | 'edits' | 'result';
    key: string;
    sequence: number;
    role: 'assistant';
    text?: string;
    detail?: string;
    files?: string[];
    added?: number;
    removed?: number;
};

export type ThreadEventState = {
    items: ConversationItem[];
    readUuids: string[];
    sequence: number;
    connection: 'live' | 'catching_up' | 'offline';
};

const emptyState: ThreadEventState = { items: [], readUuids: [], sequence: 0, connection: 'offline' };

export function useThreadEvents(threadId: number): ThreadEventState {
    void threadId;

    return emptyState;
}
