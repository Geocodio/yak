import type { ChildCardData, RepositoryCardData } from '@/types/thread-events';

export type ConversationItem = {
    kind: 'text' | 'thinking' | 'tools' | 'edits' | 'result' | 'child' | 'repository';
    key: string;
    sequence: number;
    role: 'assistant';
    text?: string;
    /** Server-rendered markdown for `text`; plain `text` is shown when absent. */
    html?: string;
    detail?: string;
    /** Wall time of a step; set only when the event payload carries it. */
    durationMs?: number;
    /** True while a tools item still has a tool use without a result. */
    running?: boolean;
    model?: string;
    files?: string[];
    added?: number;
    removed?: number;
    child?: ChildCardData;
    repository?: RepositoryCardData;
};
