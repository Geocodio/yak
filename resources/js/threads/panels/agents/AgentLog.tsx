import { Button, cn } from '@geocodio/console-ui';
import type { ReactNode } from 'react';
import { useRunLog } from '@/components/threads/conversation/useRunLog';
import { log } from '@/routes/threads/agents';
import type { TranscriptEntry } from '@/types/tasks';
import { useAgents } from './AgentsContext';
import { isRunning, type AgentRowData } from './agentsState';

const SUBAGENT_TOOLS = new Set(['Agent', 'Task', 'Workflow']);

function StepRow({ entry, isLast, isLive }: { entry: TranscriptEntry; isLast: boolean; isLive: boolean }) {
    const isRunningStep = isLive && isLast && entry.kind === 'tool' && (entry.output === null || entry.output === undefined);

    if (entry.kind === 'tool') {
        return (
            <div className="flex gap-2.5" data-testid="agent-log-step">
                <span className="w-[54px] shrink-0 truncate font-mono text-[12px] text-muted">{entry.tool ?? entry.badge}</span>
                <span className={cn('min-w-0 flex-1 break-words', entry.error && 'text-fail')}>{entry.subject !== undefined && entry.subject !== '' ? entry.subject : entry.text}</span>
                {isRunningStep && <span className="shrink-0 text-[12px] text-info">running</span>}
            </div>
        );
    }

    return (
        <p className={cn('whitespace-pre-wrap break-words', entry.kind === 'assistant' ? 'text-body' : 'text-muted')} data-testid="agent-log-text">
            {entry.fullText ?? entry.text}
        </p>
    );
}

/**
 * One agent's own steps, read in pages from the agent log route. A step where the agent started another agent shows
 * that agent as a card of its own, so nested agents nest. While the agent runs the log reads itself again.
 */
export function AgentLog({ agent, renderNested, renderTask, heading, size = 'comfortable' }: { agent: AgentRowData; renderNested?: (child: AgentRowData) => ReactNode; /** Draws the task Claude gave the agent, when its log starts with it. */ renderTask?: (task: string) => ReactNode; /** A heading above the steps. */ heading?: string; size?: 'compact' | 'comfortable' }) {
    const agents = useAgents();
    const url = agents === null ? null : log.url({ thread: agents.threadId, agent: agent.id });
    const { entries, status, hasMore, isLoadingMore, moreFailed, loadMore, retry } = useRunLog(url, isRunning(agent));
    const steps = entries.filter((entry) => entry.kind !== 'prompt');
    const task = entries.find((entry) => entry.kind === 'prompt')?.prompt?.user ?? null;
    const unusedChildren = (agents?.rows ?? []).filter((row) => row.parentTaskId === agent.id);
    const used = new Set<string>();

    if (status === 'loading') {
        return (
            <div className="flex flex-col gap-2" role="status" aria-label="Loading the log" data-testid="agent-log-loading">
                {[0, 1, 2].map((line) => (
                    <span key={line} className="h-3.5 animate-pulse rounded-chip bg-panel-2 motion-reduce:animate-none" style={{ width: `${88 - line * 18}%` }} />
                ))}
            </div>
        );
    }

    if (status === 'error') {
        return (
            <div className="flex items-center gap-2 text-[13px] text-fail" role="alert" data-testid="agent-log-error">
                <span>The log did not load.</span>
                <Button variant="secondary" className="h-7" onClick={retry} data-testid="agent-log-retry">
                    Try again
                </Button>
            </div>
        );
    }

    if (steps.length === 0) {
        return (
            <p className="text-[13px] text-muted" data-testid="agent-log-empty">
                {isRunning(agent) ? 'No steps yet. They show here as the agent works.' : 'This agent left no steps.'}
            </p>
        );
    }

    return (
        <>
        {task !== null && renderTask?.(task)}
        <div className={cn('flex flex-col text-[13px] text-body', size === 'compact' ? 'gap-2' : 'gap-3')} data-testid="agent-log">
            {heading !== undefined && <h3 className="text-[12px] font-semibold text-muted">{heading}</h3>}
            {steps.map((entry, index) => {
                const nested = renderNested !== undefined && entry.kind === 'tool' && entry.tool !== undefined && SUBAGENT_TOOLS.has(entry.tool) ? unusedChildren.find((child) => !used.has(child.id) && (child.description === entry.subject || child.workflowName === entry.subject)) ?? unusedChildren.find((child) => !used.has(child.id)) : undefined;

                if (nested !== undefined && renderNested !== undefined) {
                    used.add(nested.id);

                    return <div key={entry.id}>{renderNested(nested)}</div>;
                }

                return <StepRow key={entry.id} entry={entry} isLast={index === steps.length - 1 && !hasMore} isLive={isRunning(agent)} />;
            })}
            {hasMore && (
                <div className="flex items-center gap-2">
                    <Button variant="secondary" className="h-7" pending={isLoadingMore} onClick={loadMore} data-testid="agent-log-more">
                        Load more steps
                    </Button>
                    {moreFailed && (
                        <span className="text-[12px] text-fail" role="alert">
                            The next steps did not load.
                        </span>
                    )}
                </div>
            )}
        </div>
        </>
    );
}
