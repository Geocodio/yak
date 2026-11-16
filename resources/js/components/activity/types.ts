import type { ActivityCategory } from '@/types/tasks';

export type StepCategory = Extract<ActivityCategory, 'command' | 'read' | 'edit' | 'search' | 'fetch' | 'thinking'>;

/** The text an opened step shows. A task's steps leave it out and fetch it by `logId`; a thread's carry it. */
export type StepContent = {
    input?: string | null;
    output?: string | null;
    thought?: string | null;
};

/** One step as the shared rows draw it, whether it came from a task's activity log or a thread's events. */
export type StepView = {
    key: string;
    /** The task log row behind the step; enables on-demand content and "Open in full log". */
    logId: number | null;
    category: StepCategory;
    /** The tool's name for the footer, or null for thinking. */
    tool: string | null;
    /** The command, path, pattern or thought on the line. */
    label: string;
    durationMs: number | null;
    exitCode: number | null;
    failed: boolean;
    running: boolean;
    /** A passing run of a command that failed earlier. */
    retryPassed: boolean;
    /** A failing run that a later identical command passed. */
    retried: boolean;
    /** Wall clock time of the step, as the log shows it. */
    at: string | null;
    /** ISO time the step started, for the running step's ticking clock. */
    createdAt: string | null;
    content?: StepContent;
};
