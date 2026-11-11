import type { ChildCardData } from '@/types/thread-events';

export type ConversationItem = {
    kind: 'text' | 'thinking' | 'tools' | 'edits' | 'result' | 'child';
    key: string;
    sequence: number;
    role: 'assistant';
    text?: string;
    /** Server-rendered markdown for `text`; plain `text` is shown when absent. */
    html?: string;
    detail?: string;
    files?: string[];
    added?: number;
    removed?: number;
    child?: ChildCardData;
};
