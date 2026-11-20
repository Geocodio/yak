import { Button } from '@geocodio/console-ui';
import { ArrowLeft } from 'lucide-react';
import { formatCount, formatWhen } from '@/lib/format';
import type { ThreadAgentMessage } from '@/types/thread-agents';
import { AgentLog } from './AgentLog';
import { AgentMessageBox, AgentMessageList } from './AgentMessages';
import { agentDurationMs, agentTitle, formatAgentDuration, isRunning, type AgentRowData } from './agentsState';
import { useAgents } from './AgentsContext';
import { StopAgentButton } from './StopControls';

function Stat({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex flex-col gap-0.5">
            <span className="text-[12px] text-muted">{label}</span>
            <span className="text-[15px] font-medium text-body">{value}</span>
        </div>
    );
}

const Section = ({ title, children }: { title: string; children: React.ReactNode }) => (
    <section className="flex flex-col gap-1.5 border-b border-hair px-5 py-3.5">
        <h3 className="text-[12px] font-semibold text-muted">{title}</h3>
        {children}
    </section>
);

/** The selected agent: what it is, what it has used, the task it was given, its log, and a box to message it. */
export function AgentDetail({ agent, parent, modelLabels, localMessages, onSent, onBack }: { agent: AgentRowData; parent: AgentRowData | null; modelLabels: Record<string, string>; localMessages: ThreadAgentMessage[]; onSent: (message: ThreadAgentMessage) => void; onBack: () => void }) {
    const agents = useAgents();

    if (agents === null) {
        return null;
    }

    const startedChildren = agents.rows.filter((row) => row.parentTaskId === agent.id).length;
    const kind = agent.kind === 'workflow' ? 'Workflow' : 'Agent';
    const meta = [
        kind,
        agent.agentType,
        agent.model === null ? null : (modelLabels[agent.model] ?? agent.model),
        `started by ${parent === null ? 'Claude' : agentTitle(parent)}${agent.startedAt === null ? '' : ` at ${formatWhen(agent.startedAt)}`}`,
        agent.kind === 'agent' ? "works on this thread's branch" : null,
    ]
        .filter((part) => part !== null && part !== '')
        .join(' · ');

    return (
        <div className="flex min-h-0 min-w-0 flex-1 flex-col" data-testid="agent-detail" data-agent-id={agent.id}>
            <div className="flex-1 overflow-y-auto">
                <div className="flex items-start gap-3 border-b border-hair px-5 py-4">
                    <Button variant="tertiary" className="h-8 shrink-0 @2xl:hidden" icon={<ArrowLeft size={14} />} onClick={onBack} aria-label="Back to all agents" data-testid="agent-detail-back" />
                    <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                        <h2 className="break-words text-[16px] font-semibold text-body" data-testid="agent-detail-title">
                            {agentTitle(agent)}
                        </h2>
                        <p className="text-[12px] text-muted">{meta}</p>
                    </div>
                    {agents.canDrive && <StopAgentButton threadId={agents.threadId} agent={agent} label={agent.kind === 'workflow' ? 'Stop workflow' : 'Stop agent'} />}
                </div>
                {agent.stopError !== null && isRunning(agent) && (
                    <p role="alert" className="border-b border-hair bg-fail-soft px-5 py-2 text-[12.5px] text-fail" data-testid="agent-stop-error">
                        Claude could not stop this agent: {agent.stopError}
                    </p>
                )}
                <div className="grid grid-cols-2 gap-3 border-b border-hair px-5 py-3.5 sm:grid-cols-4">
                    <Stat label={isRunning(agent) ? 'Running' : agent.status === 'completed' ? 'Ran for' : agent.status === 'stopped' ? 'Stopped after' : 'Failed after'} value={formatAgentDuration(agentDurationMs(agent, agents.now))} />
                    <Stat label="Tokens" value={formatCount(agent.totalTokens)} />
                    <Stat label="Tool uses" value={formatCount(agent.toolUses)} />
                    <Stat label="Agents it started" value={String(startedChildren)} />
                </div>
                {agent.summary !== null && agent.summary !== '' && (
                    <Section title={isRunning(agent) ? 'Latest' : 'Result'}>
                        <p className="whitespace-pre-wrap break-words text-[13px] text-body" data-testid="agent-detail-summary">
                            {agent.summary}
                        </p>
                    </Section>
                )}
                <div className="flex flex-col gap-3 px-5 py-3.5">
                    <AgentLog
                        agent={agent}
                        heading="Log"
                        renderTask={(task) => (
                            <div className="-mx-5 -mt-3.5 mb-1 flex flex-col gap-1.5 border-b border-hair px-5 py-3.5" data-testid="agent-detail-task">
                                <h3 className="text-[12px] font-semibold text-muted">Task from Claude</h3>
                                <p className="whitespace-pre-wrap break-words text-[13px] text-body">{task}</p>
                            </div>
                        )}
                    />
                    <AgentMessageList agent={agent} local={localMessages} />
                </div>
            </div>
            {agents.canDrive && <AgentMessageBox threadId={agents.threadId} agent={agent} onSent={onSent} />}
        </div>
    );
}
