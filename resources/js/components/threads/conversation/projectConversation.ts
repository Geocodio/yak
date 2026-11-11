import type { AssistantBlock, ThreadEventState, TranscriptEntry } from '@/types/thread-events';
import type { ConversationItem } from './conversationItem';

type ToolUse = Extract<AssistantBlock, { type: 'tool_use' }>;
type Source = Pick<ThreadEventState, 'entries' | 'streamingBlocks' | 'lastSequence'> & Partial<Pick<ThreadEventState, 'children' | 'repositories'>>;

const editTools = new Set(['Edit', 'MultiEdit', 'Write']);

const toolVerbs: Record<string, [string, string, string]> = {
    Read: ['Read', 'file', 'files'],
    Bash: ['Ran', 'command', 'commands'],
    Grep: ['Searched', 'pattern', 'patterns'],
    Glob: ['Searched', 'pattern', 'patterns'],
};

function lineCount(value: unknown): number {
    return typeof value === 'string' && value !== '' ? value.split('\n').length : 0;
}

function summarize(tools: ToolUse[]): string {
    const counts = new Map<string, number>();
    tools.forEach((tool) => counts.set(tool.name, (counts.get(tool.name) ?? 0) + 1));

    return [...counts]
        .map(([name, count]) => {
            const [verb, singular, plural] = toolVerbs[name] ?? ['Used', name, name];

            return `${verb} ${count} ${count === 1 ? singular : plural}`;
        })
        .join(', ');
}

function editItem(messageId: string, sequence: number, edits: ToolUse[]): ConversationItem {
    const files = new Set<string>();
    let added = 0;
    let removed = 0;

    edits.forEach((tool) => {
        if (typeof tool.input.file_path === 'string') {
            files.add(tool.input.file_path);
        }

        if (tool.name === 'Write') {
            added += lineCount(tool.input.content);

            return;
        }

        const pairs = tool.name === 'MultiEdit' && Array.isArray(tool.input.edits) ? (tool.input.edits as Record<string, unknown>[]) : [tool.input];
        pairs.forEach((pair) => {
            added += lineCount(pair.new_string);
            removed += lineCount(pair.old_string);
        });
    });

    return { kind: 'edits', key: `edits:${messageId}`, sequence, role: 'assistant', files: [...files], added, removed };
}

function projectAssistant(entry: Extract<TranscriptEntry, { role: 'assistant' }>, sequence: number, results: Map<string, string>): ConversationItem[] {
    const items: ConversationItem[] = [];
    const edits: ToolUse[] = [];
    let run: { firstIndex: number; tools: ToolUse[] } | null = null;

    const closeRun = () => {
        if (run) {
            const detail = run.tools.map((tool) => results.get(tool.id)).filter((text): text is string => text !== undefined);
            items.push({
                kind: 'tools',
                key: `tools:${entry.messageId}:${run.firstIndex}`,
                sequence,
                role: 'assistant',
                text: summarize(run.tools),
                ...(detail.length > 0 ? { detail: detail.join('\n\n') } : {}),
            });
            run = null;
        }
    };

    entry.blocks.forEach((block, index) => {
        if (block.type === 'tool_use') {
            if (editTools.has(block.name)) {
                edits.push(block);
            } else {
                run ??= { firstIndex: index, tools: [] };
                run.tools.push(block);
            }

            return;
        }

        closeRun();
        items.push({ kind: block.type, key: `${entry.messageId}:${index}`, sequence, role: 'assistant', text: block.text });
    });

    closeRun();

    if (edits.length > 0) {
        items.push(editItem(entry.messageId, sequence, edits));
    }

    return items;
}

/** Turns the live thread state into conversation items plus the sequence at which each user message was read. */
export function projectConversation(state: Source, entrySequences: number[]): { items: ConversationItem[]; readSequences: Record<string, number> } {
    const results = new Map<string, string>();
    state.entries.forEach((entry) => {
        if (entry.role === 'tool_result') {
            results.set(entry.toolUseId, entry.text);
        }
    });

    const items: ConversationItem[] = [];
    const readSequences: Record<string, number> = {};

    state.entries.forEach((entry, index) => {
        const sequence = entrySequences[index] ?? state.lastSequence;

        if (entry.role === 'assistant') {
            items.push(...projectAssistant(entry, sequence, results));
        } else if (entry.role === 'result') {
            items.push({ kind: 'result', key: `result:${sequence}`, sequence, role: 'assistant', text: entry.text });
        } else if (entry.role === 'user' && entry.uuid) {
            readSequences[entry.uuid] = sequence;
        }
    });

    Object.values(state.children ?? {}).forEach(({ sequence, data }) => {
        items.push({ kind: 'child', key: `child:${data.childThreadId}`, sequence, role: 'assistant', child: data });
    });

    Object.values(state.repositories ?? {}).forEach(({ sequence, data }) => {
        items.push({ kind: 'repository', key: `repository:${data.repo}`, sequence, role: 'assistant', repository: data });
    });

    Object.entries(state.streamingBlocks)
        .filter(([, block]) => block.kind === 'text' || block.kind === 'thinking')
        .forEach(([key, block], order) => {
            items.push({ kind: block.kind as 'text' | 'thinking', key, sequence: state.lastSequence + 1 + order, role: 'assistant', text: block.text });
        });

    return { items, readSequences };
}
