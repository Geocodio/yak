import type { ActivityCategory, ActivityCounts, ActivityRow } from '@/types/tasks';

/** A tool call or a thinking step, with its place in a retry pair. */
export type ActivityStep = ActivityRow & {
    /** A passing run of a command that failed earlier. */
    retryPassed: boolean;
    /** A failing run that a later identical command passed. */
    retried: boolean;
};

export type ActivityNode =
    | { type: 'narrative'; key: string; row: ActivityRow }
    | { type: 'group'; key: string; steps: ActivityStep[] }
    | { type: 'milestone'; key: string; row: ActivityRow };

const STEP_CATEGORIES: ActivityCategory[] = ['command', 'read', 'edit', 'search', 'fetch', 'thinking'];

export function isStep(row: ActivityRow): boolean {
    return STEP_CATEGORIES.includes(row.category);
}

/**
 * Projects the flat activity rows into what the stream shows: the agent's
 * sentences, runs of consecutive steps (tool calls and thinking) as one
 * group each, and milestones. Prompt rows carry the whole system prompt and
 * stay out of the stream; the full log shows them.
 *
 * A passing command that repeats an earlier failing one is marked
 * `retryPassed`, and the failing row `retried`, across the whole list so a
 * pair split by a sentence is still found.
 */
export function groupActivity(rows: ActivityRow[]): ActivityNode[] {
    const lastFailedByCommand = new Map<string, ActivityStep>();
    const nodes: ActivityNode[] = [];

    for (const row of rows) {
        if (row.kind === 'prompt') {
            continue;
        }

        if (!isStep(row)) {
            const isNarrative = row.category === 'narrative';
            nodes.push({ type: isNarrative ? 'narrative' : 'milestone', key: `${isNarrative ? 'n' : 'm'}${row.id}`, row });
            continue;
        }

        const step: ActivityStep = { ...row, retryPassed: false, retried: false };

        if (row.tool !== null && row.category !== 'thinking') {
            const commandKey = `${row.tool}:${row.label}`;
            const failed = lastFailedByCommand.get(commandKey);

            if (row.error) {
                lastFailedByCommand.set(commandKey, step);
            } else if (failed && !row.running) {
                step.retryPassed = true;
                failed.retried = true;
                lastFailedByCommand.delete(commandKey);
            }
        }

        const last = nodes[nodes.length - 1];

        if (last?.type === 'group') {
            last.steps.push(step);
        } else {
            nodes.push({ type: 'group', key: `g${row.id}`, steps: [step] });
        }
    }

    return nodes;
}

/** True when the rows hold failures and a later passing run followed every one of them. */
export function allFailuresRetried(rows: ActivityRow[]): boolean {
    const failed = groupActivity(rows).flatMap((node) => (node.type === 'group' ? node.steps.filter((step) => step.error) : []));

    return failed.length > 0 && failed.every((step) => step.retried);
}

export function countSteps(steps: ActivityRow[]): ActivityCounts {
    const counts: ActivityCounts = { command: 0, read: 0, edit: 0, search: 0, fetch: 0, thinking: 0 };

    steps.forEach((step) => {
        if (step.category in counts) {
            counts[step.category as keyof ActivityCounts] += 1;
        }
    });

    return counts;
}

const PHRASES: Record<keyof ActivityCounts, (count: number) => string> = {
    command: (count) => `ran ${count} ${count === 1 ? 'command' : 'commands'}`,
    read: (count) => `read ${count} ${count === 1 ? 'file' : 'files'}`,
    edit: (count) => `edited ${count} ${count === 1 ? 'file' : 'files'}`,
    search: (count) => `searched ${count} ${count === 1 ? 'time' : 'times'}`,
    fetch: (count) => `fetched ${count} ${count === 1 ? 'page' : 'pages'}`,
    thinking: (count) => `${count} thinking ${count === 1 ? 'step' : 'steps'}`,
};

export const COUNT_ORDER = ['command', 'read', 'edit', 'search', 'fetch', 'thinking'] as const satisfies readonly (keyof ActivityCounts)[];

/** "Ran 14 commands, read 6 files, 3 thinking steps" */
export function describeCounts(counts: ActivityCounts): string {
    const text = COUNT_ORDER.filter((category) => counts[category] > 0)
        .map((category) => PHRASES[category](counts[category]))
        .join(', ');

    return text.charAt(0).toUpperCase() + text.slice(1);
}

/** Seconds as the stream writes them: `38s`, `1m 27s`, `1h 05m`. */
export function formatSeconds(totalSeconds: number): string {
    const seconds = Math.max(0, Math.floor(totalSeconds));

    if (seconds < 60) {
        return `${seconds}s`;
    }

    if (seconds < 3600) {
        return `${Math.floor(seconds / 60)}m ${String(seconds % 60).padStart(2, '0')}s`;
    }

    return `${Math.floor(seconds / 3600)}h ${String(Math.floor((seconds % 3600) / 60)).padStart(2, '0')}m`;
}

/** A step's own duration: sub-second steps read `0.4s`; nothing recorded reads as no duration. */
export function formatStepDuration(durationMs: number | null): string | null {
    if (durationMs === null) {
        return null;
    }

    return durationMs < 1000 ? `${(durationMs / 1000).toFixed(1)}s` : formatSeconds(Math.round(durationMs / 1000));
}

/** Total wall time of a group: the sum of its recorded step durations, or null when none were recorded. */
export function groupDuration(steps: ActivityRow[]): string | null {
    const recorded = steps.filter((step) => step.durationMs !== null);

    return recorded.length === 0 ? null : formatSeconds(Math.round(recorded.reduce((total, step) => total + (step.durationMs ?? 0), 0) / 1000));
}
