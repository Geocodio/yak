import { Button, IconButton, cn } from '@geocodio/console-ui';
import { Check, ChevronDown, ChevronUp, ScrollText } from 'lucide-react';
import { useId, useState } from 'react';
import { AgentLog } from '@/threads/panels/agents/AgentLog';
import { AgentStatusDot } from '@/threads/panels/agents/AgentStatusDot';
import { useAgents } from '@/threads/panels/agents/AgentsContext';
import { agentDurationMs, agentTitle, cardSubtitle, formatAgentDuration, isRunning, workflowPhases, type AgentRowData, type WorkflowPhase } from '@/threads/panels/agents/agentsState';
import { StopAgentButton } from '@/threads/panels/agents/StopControls';

const TILE: Record<WorkflowPhase['state'], string> = { done: 'bg-ok-soft text-body', running: 'bg-brand-soft text-brand', waiting: 'bg-panel-2 text-muted' };

function PhaseTiles({ agent }: { agent: AgentRowData }) {
    const phases = workflowPhases(agent.workflowProgress);

    if (phases.length === 0) {
        return null;
    }

    return (
        <ul className="grid gap-2 px-3.5 pb-3 text-[12px]" style={{ gridTemplateColumns: `repeat(${Math.min(phases.length, 3)}, minmax(0, 1fr))` }} data-testid="workflow-phases">
            {phases.map((phase) => (
                <li key={phase.title} className={cn('rounded-card px-2.5 py-2', TILE[phase.state])} data-state={phase.state} data-testid="workflow-phase">
                    {phase.title} · {phase.label}
                </li>
            ))}
        </ul>
    );
}

/**
 * One agent or workflow where Claude called it. The card says what the agent does right now, stops it, and opens to
 * the agent's own steps; an agent it started shows as a card inside. `fallbackTitle` stands in until the first
 * `agent.started` event arrives.
 */
export function AgentCard({ agent, fallbackTitle, isNested = false }: { agent: AgentRowData | undefined; fallbackTitle: string; isNested?: boolean }) {
    const agents = useAgents();
    const [isExpanded, setIsExpanded] = useState(false);
    const bodyId = useId();

    if (agent === undefined || agents === null) {
        return (
            <div className={cn('rounded-card border border-hair bg-panel px-3.5 py-3', !isNested && 'ml-9')} data-testid="agent-card" data-status="starting">
                <div className="flex items-center gap-3">
                    <span className="inline-block size-2.5 shrink-0 rounded-pill bg-idle" aria-hidden />
                    <div className="flex min-w-0 flex-col gap-0.5">
                        <span className="break-words text-[14px] font-medium text-body">{fallbackTitle}</span>
                        <span className="text-[12px] text-muted">Starting</span>
                    </div>
                </div>
            </div>
        );
    }

    const isWorkflow = agent.kind === 'workflow';
    const toggleLabel = isExpanded ? 'Collapse agent log' : 'Expand agent log';

    return (
        <div className={cn('overflow-hidden rounded-card border border-hair bg-panel', !isNested && 'ml-9')} data-testid="agent-card" data-agent-id={agent.id} data-status={agent.status} data-kind={agent.kind}>
            <div className="flex items-center gap-3 px-3.5 py-3">
                <AgentStatusDot status={agent.status} className="size-2.5" />
                <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                    <span className="break-words text-[14px] font-medium text-body" data-testid="agent-card-title">
                        {agentTitle(agent) || fallbackTitle}
                    </span>
                    <span className="break-words text-[12px] text-muted" data-testid="agent-card-subtitle">
                        {cardSubtitle(agent, agents.now, isExpanded)}
                    </span>
                </div>
                {agents.canDrive && <StopAgentButton threadId={agents.threadId} agent={agent} className="h-8 shrink-0" />}
                <IconButton label={toggleLabel} aria-expanded={isExpanded} aria-controls={bodyId} onClick={() => setIsExpanded((value) => !value)} data-testid="agent-card-toggle">
                    {isExpanded ? <ChevronUp size={16} aria-hidden /> : <ChevronDown size={16} aria-hidden />}
                </IconButton>
            </div>
            {isWorkflow && <PhaseTiles agent={agent} />}
            {agent.stopError !== null && isRunning(agent) && (
                <p role="alert" className="px-3.5 pb-2.5 text-[12px] text-fail" data-testid="agent-card-stop-error">
                    Claude could not stop this agent: {agent.stopError}
                </p>
            )}
            {isExpanded && (
                <div id={bodyId} className="flex flex-col gap-2.5 border-t border-hair bg-panel-2 px-3.5 py-3 text-[13px] text-body" data-testid="agent-card-body">
                    {agent.summary !== null && agent.summary !== '' && (
                        <p className="text-muted" data-testid="agent-card-summary">
                            Summary: {agent.summary}
                        </p>
                    )}
                    <div className="max-h-80 overflow-y-auto" tabIndex={0} role="region" aria-label="Agent steps">
                        <AgentLog agent={agent} size="compact" renderNested={(child) => <AgentCard agent={child} fallbackTitle={agentTitle(child)} isNested />} />
                    </div>
                    <div>
                        <Button variant="link" icon={<ScrollText size={13} aria-hidden />} onClick={() => agents.openFullLog(agent)} data-testid="agent-card-full-log">
                            {agent.toolUses > 0 ? `Open full log (${agent.toolUses} steps)` : 'Open full log'}
                        </Button>
                    </div>
                </div>
            )}
        </div>
    );
}

/** The card for one `agent` conversation item: the agent Claude started with that tool call, once its first event arrived. */
export function AgentItemCard({ toolUseId, fallbackTitle }: { toolUseId: string; fallbackTitle: string }) {
    const agents = useAgents();

    return <AgentCard agent={agents?.rows.find((row) => row.toolUseId === toolUseId)} fallbackTitle={fallbackTitle} />;
}

/** The line between an agent ending and Claude's reply to it: nobody wrote a message, the agent's end started the turn. */
export function AgentContinuedRow({ agent }: { agent: AgentRowData }) {
    const agents = useAgents();
    const text = formatAgentDuration(agentDurationMs(agent, 0));

    return (
        <div className="ml-9 flex items-center gap-2 text-[13px] text-muted" data-testid="agent-continued-row" data-agent-id={agent.id}>
            <Check size={16} aria-hidden className="shrink-0" />
            <span>
                <button type="button" className="cursor-pointer font-medium text-body hover:underline" onClick={() => agents?.openPanel(agent.id)} data-testid="agent-continued-open">
                    {agentTitle(agent)}
                </button>{' '}
                {agent.status === 'completed' ? 'finished' : agent.status} after {text}. Claude continued.
            </span>
        </div>
    );
}
