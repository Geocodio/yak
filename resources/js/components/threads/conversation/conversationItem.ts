import type { StepView } from '@/components/activity/types';
import type { ChildCardData, CutReason, ChildResultData, RepositoryCardData } from '@/types/thread-events';

export type ConversationItem = {
    kind: 'text' | 'thinking' | 'tools' | 'edits' | 'result' | 'child' | 'childResult' | 'repository' | 'agent';
    key: string;
    sequence: number;
    role: 'assistant';
    text?: string;
    /** Rendered markdown for `text`: the server's for a final item, the streaming render while it streams; plain `text` is shown when absent. */
    html?: string;
    detail?: string;
    /** The tool calls of a `tools` item, drawn by the shared step rows. */
    steps?: StepView[];
    /** Wall time of a step; set only when the event payload carries it. */
    durationMs?: number;
    /** True while a tools item still has a tool use without a result. */
    running?: boolean;
    model?: string;
    /** When the entry was recorded, for the time in Claude's header. */
    at?: string;
    /** True while the block is still being written. */
    streaming?: boolean;
    /** True when an interrupt ended the block before it finished. */
    cutOff?: boolean;
    /** Who ended the block: the person (`stopped`) or a session restart (`restarted`). */
    cutReason?: CutReason;
    files?: string[];
    added?: number;
    removed?: number;
    child?: ChildCardData;
    childResult?: ChildResultData;
    repository?: RepositoryCardData;
    /** The tool call of an `agent` item; the agent started by it carries the same id. */
    toolUseId?: string;
};
