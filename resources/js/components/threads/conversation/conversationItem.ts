export type ConversationItem = {
    kind: 'text' | 'thinking' | 'tools' | 'edits' | 'result';
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
};
