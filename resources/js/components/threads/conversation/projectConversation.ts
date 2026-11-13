import type { AssistantBlock, ThreadEventState, TranscriptEntry } from '@/types/thread-events';
import type { StepView } from '@/components/activity/types';
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

const toolCategories: Record<string, StepView['category']> = { Bash: 'command', Read: 'read', Grep: 'search', Glob: 'search', WebFetch: 'fetch', WebSearch: 'fetch' };

type ToolResult = { text: string; isError: boolean };

function toolLabel(tool: ToolUse): string {
    const input = tool.input;
    const pick = (key: string) => (typeof input[key] === 'string' ? (input[key] as string) : '');

    return (
        (tool.name === 'Bash' ? pick('command') : tool.name === 'Grep' || tool.name === 'Glob' ? pick('pattern') : tool.name === 'WebFetch' ? pick('url') : pick('file_path')) || tool.name
    );
}

/** One tool call as the shared step rows draw it; its result, when it has one, is the detail the row opens to. */
function toStep(tool: ToolUse, result: ToolResult | undefined): StepView {
    const label = toolLabel(tool);

    return {
        key: `tool:${tool.id}`,
        logId: null,
        category: toolCategories[tool.name] ?? 'command',
        tool: tool.name,
        label,
        durationMs: null,
        exitCode: null,
        failed: result?.isError ?? false,
        running: result === undefined,
        retryPassed: false,
        retried: false,
        at: null,
        createdAt: null,
        content: { input: label, output: result?.text ?? null },
    };
}

function summarize(toolNames: string[]): string {
    const counts = new Map<string, number>();
    toolNames.forEach((name) => counts.set(name, (counts.get(name) ?? 0) + 1));

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

function projectAssistant(entry: Extract<TranscriptEntry, { role: 'assistant' }>, sequence: number, results: Map<string, ToolResult>, model: string | undefined): ConversationItem[] {
    const items: ConversationItem[] = [];
    const edits: ToolUse[] = [];
    let run: { firstIndex: number; tools: ToolUse[] } | null = null;

    const closeRun = () => {
        if (run) {
            const detail = run.tools.map((tool) => results.get(tool.id)?.text).filter((text): text is string => text !== undefined);
            items.push({
                kind: 'tools',
                key: `tools:${entry.messageId}:${run.firstIndex}`,
                sequence,
                role: 'assistant',
                text: summarize(run.tools.map((tool) => tool.name)),
                steps: run.tools.map((tool) => toStep(tool, results.get(tool.id))),
                ...(run.tools.some((tool) => !results.has(tool.id)) ? { running: true } : {}),
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

        // A block without text draws nothing, so it neither gets an author header nor splits a run of tool calls.
        if (block.text.trim() === '' && !(block.type === 'text' && block.html)) {
            return;
        }

        items.push({ kind: block.type, key: `${entry.messageId}:${index}`, sequence, role: 'assistant', text: block.text, ...(block.type === 'text' && block.html ? { html: block.html } : {}), ...(block.type === 'text' && model ? { model } : {}) });
    });

    closeRun();

    if (edits.length > 0) {
        items.push(editItem(entry.messageId, sequence, edits));
    }

    return items;
}

/** Joins a tool run onto the tool run just before it, so calls that no visible text separates are one group. */
function joinRuns(previous: ConversationItem, item: ConversationItem): ConversationItem {
    const steps = [...(previous.steps ?? []), ...(item.steps ?? [])];
    const detail = [previous.detail, item.detail].filter((part): part is string => part !== undefined);

    return {
        ...previous,
        text: summarize(steps.map((step) => step.tool ?? '')),
        steps,
        ...(previous.running || item.running ? { running: true } : {}),
        ...(detail.length > 0 ? { detail: detail.join('\n\n') } : {}),
    };
}

/** Turns the live thread state into conversation items plus the sequence at which each user message was read. */
export function projectConversation(state: Source, entrySequences: number[]): { items: ConversationItem[]; readSequences: Record<string, number> } {
    const results = new Map<string, ToolResult>();
    state.entries.forEach((entry) => {
        if (entry.role === 'tool_result') {
            results.set(entry.toolUseId, { text: entry.text, isError: entry.isError });
        }
    });

    let model: string | undefined;
    let lastAssistantText = '';
    let personSpoke = false;
    const items: ConversationItem[] = [];
    const readSequences: Record<string, number> = {};

    state.entries.forEach((entry, index) => {
        const sequence = entrySequences[index] ?? state.lastSequence;

        if (entry.role === 'assistant') {
            projectAssistant(entry, sequence, results, model).forEach((item) => {
                const previous = items[items.length - 1];
                const joinsPrevious = item.kind === 'tools' && previous?.kind === 'tools' && !personSpoke;

                if (joinsPrevious) {
                    items[items.length - 1] = joinRuns(previous, item);
                } else {
                    items.push(item);
                }

                personSpoke = false;
            });
            entry.blocks.forEach((block) => {
                if (block.type === 'text') {
                    lastAssistantText = block.text;
                }
            });
        } else if (entry.role === 'system' && entry.model) {
            model = entry.model;
        } else if (entry.role === 'result') {
            // The result repeats the final assistant text; it is shown only when the turn had no such text.
            const resultText = entry.text?.trim() ?? '';
            const isRepeat = resultText !== '' && lastAssistantText.includes(resultText);
            lastAssistantText = '';

            if (!isRepeat) {
                items.push({ kind: 'result', key: `result:${sequence}`, sequence, role: 'assistant', text: entry.text, ...(entry.html ? { html: entry.html } : {}) });
            }
        } else if (entry.role === 'user') {
            // A person's message sits between the calls on either side of it, so they stay separate groups.
            personSpoke = true;

            if (entry.uuid) {
                readSequences[entry.uuid] = sequence;
            }
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
