import { Link } from '@inertiajs/react';
import { cn } from '@geocodio/console-ui';
import { Bot, ExternalLink, GitBranch, Workflow } from 'lucide-react';
import { useMemo, useState } from 'react';
import { ChildStateDot, CHILD_STATE_LABEL } from '@/components/threads/stack/ChildStateDot';
import { show } from '@/routes/threads';
import type { PanelProps } from '@/threads/panels';
import type { ThreadAgentMessage, ThreadAgentsData, ThreadSubThread } from '@/types/thread-agents';
import { AgentDetail } from './AgentDetail';
import { AgentStatusDot } from './AgentStatusDot';
import { useAgents } from './AgentsContext';
import { agentTitle, buildTree, flattenTree, rowSubtitle, runningSummary, type AgentListRow } from './agentsState';
import { StopAllControl } from './StopControls';

const INDENT_PX = 22;

function SectionHeader({ title, definition, action }: { title: string; definition: string; action?: React.ReactNode }) {
    return (
        <div className="flex items-start justify-between gap-2.5 px-3.5 pb-1.5 pt-3.5">
            <div className="flex min-w-0 flex-col gap-0.5">
                <h2 className="text-[12px] font-semibold uppercase tracking-[0.04em] text-muted">{title}</h2>
                <p className="text-[12px] text-muted">{definition}</p>
            </div>
            {action}
        </div>
    );
}

const rowClass = 'flex w-full min-w-0 items-start gap-2.5 rounded-card py-2 pr-2 text-left focus-visible:outline-2 focus-visible:outline-accent';

function MainRow({ main }: { main: ThreadAgentsData['main'] }) {
    const state = { working: 'Working', waiting: `Waiting for ${main.runningAgents === 1 ? '1 agent' : `${main.runningAgents} agents`}`, idle: 'Idle' }[main.state];
    const steps = main.state === 'working' && main.stepsThisTurn !== null ? ` · ${main.stepsThisTurn} ${main.stepsThisTurn === 1 ? 'step' : 'steps'} this turn` : '';

    return (
        <li className={cn(rowClass, 'pl-2')} data-testid="agents-main-row">
            <span className={cn('mt-[7px] inline-block size-2 shrink-0 rounded-pill', main.state === 'working' ? 'animate-pulse bg-info motion-reduce:animate-none' : main.state === 'waiting' ? 'bg-warn' : 'bg-idle')} aria-hidden />
            <span className="mt-0.5 grid size-[18px] shrink-0 place-items-center rounded-pill bg-brand-soft text-[11px] font-semibold text-brand" aria-hidden>
                C
            </span>
            <span className="flex min-w-0 flex-col gap-0.5">
                <span className="text-[14px] font-medium text-body">Claude (this thread)</span>
                <span className="text-[12px] text-muted">{`Main agent · ${state}${steps}`}</span>
            </span>
        </li>
    );
}

function AgentTreeRow({ row, isSelected, now, onSelect }: { row: AgentListRow; isSelected: boolean; now: number; onSelect: () => void }) {
    const Icon = row.agent.kind === 'workflow' ? Workflow : Bot;

    return (
        <li>
            <button
                type="button"
                aria-pressed={isSelected}
                onClick={onSelect}
                className={cn(rowClass, 'cursor-pointer hover:bg-panel-2', isSelected && 'bg-panel-2')}
                style={{ paddingLeft: 8 + INDENT_PX * (row.depth + 1) }}
                data-testid="agent-row"
                data-agent-id={row.agent.id}
                data-status={row.agent.status}
            >
                <AgentStatusDot status={row.agent.status} className="mt-[7px]" />
                <Icon size={16} aria-hidden className="mt-0.5 shrink-0 text-muted" />
                <span className="flex min-w-0 flex-col gap-0.5">
                    <span className={cn('break-words font-medium text-body', row.depth === 0 ? 'text-[14px]' : 'text-[13px]')}>{agentTitle(row.agent)}</span>
                    <span className="break-words text-[12px] text-muted">{rowSubtitle(row.agent, row.parent, now)}</span>
                </span>
            </button>
        </li>
    );
}

function pullRequestNumber(url: string): string | null {
    return /\/pull\/(\d+)/.exec(url)?.[1] ?? null;
}

function SubThreadRow({ subThread }: { subThread: ThreadSubThread }) {
    const number = subThread.pullRequestUrl === null ? null : pullRequestNumber(subThread.pullRequestUrl);
    const detail = [
        'Sub-thread',
        subThread.branch === null ? null : `branch ${subThread.branch}`,
        subThread.pullRequestUrl === null ? null : number === null ? 'PR' : `PR #${number}`,
        subThread.state === 'waiting' ? 'Needs you' : (subThread.step ?? CHILD_STATE_LABEL[subThread.state]),
    ]
        .filter((part) => part !== null)
        .join(' · ');

    return (
        <li className="flex items-start gap-1" data-testid="agents-subthread-row" data-thread-id={subThread.id}>
            <Link href={show.url(subThread.id)} className={cn(rowClass, 'flex-1 pl-2 hover:bg-panel-2')}>
                <span className="mt-[7px]">
                    <ChildStateDot state={subThread.state} />
                </span>
                <GitBranch size={16} aria-hidden className="mt-0.5 shrink-0 text-muted" />
                <span className="flex min-w-0 flex-col gap-0.5">
                    <span className="break-words text-[14px] font-medium text-body">{subThread.title}</span>
                    <span className="break-words text-[12px] text-muted">{detail}</span>
                </span>
            </Link>
            {subThread.pullRequestUrl !== null && (
                <a href={subThread.pullRequestUrl} target="_blank" rel="noreferrer" aria-label="Open the pull request" className="mt-2 grid size-6 shrink-0 place-items-center rounded-chip text-muted hover:bg-panel-2 hover:text-body">
                    <ExternalLink size={13} aria-hidden />
                </a>
            )}
        </li>
    );
}

/** Claude and everything working beside it on this thread, as a tree, with the selected agent's log beside it. */
export function AgentsPanel({ modelLabels = {} }: PanelProps) {
    const agents = useAgents();
    const [sentMessages, setSentMessages] = useState<Record<string, ThreadAgentMessage[]>>({});
    const rows = useMemo(() => flattenTree(buildTree(agents?.rows ?? [])), [agents?.rows]);

    if (agents === null) {
        return null;
    }

    const selected = rows.find((row) => row.agent.id === agents.selectedId) ?? null;
    const summary = runningSummary(agents.running);
    const runningCount = agents.running.agents + agents.running.workflows;
    const isEmpty = agents.rows.length === 0 && agents.subThreads.length === 0;

    return (
        <div className="@container flex min-h-0 flex-1 flex-col" data-testid="agents-panel">
            <div className="flex min-h-0 flex-1 flex-col @2xl:flex-row">
                <div className={cn('min-h-0 flex-col overflow-y-auto border-hair pb-4 @2xl:flex @2xl:w-[360px] @2xl:shrink-0 @2xl:border-r', selected === null ? 'flex flex-1' : 'hidden')} data-testid="agents-list">
                    <SectionHeader
                        title="Claude and its agents"
                        definition="Agents are helpers Claude runs inside this thread. Same branch, same changes."
                        action={agents.canDrive && runningCount > 0 ? <StopAllControl threadId={agents.threadId} runningCount={runningCount} /> : undefined}
                    />
                    <p className="sr-only" role="status" data-testid="agents-running-summary">
                        {summary ?? 'Nothing is running.'}
                    </p>
                    <ul className="flex flex-col gap-0.5 px-2">
                        <MainRow main={agents.main} />
                        {rows.map((row) => (
                            <AgentTreeRow key={row.agent.id} row={row} isSelected={row.agent.id === agents.selectedId} now={agents.now} onSelect={() => agents.select(row.agent.id)} />
                        ))}
                    </ul>
                    {isEmpty && (
                        <p className="px-3.5 pt-3 text-[13px] text-muted" data-testid="agents-empty">
                            No agents yet. When Claude starts helpers, they show here.
                        </p>
                    )}
                    {agents.subThreads.length > 0 && (
                        <>
                            <SectionHeader title="Sub-threads" definition="Separate threads with their own branch and PR. Open one to talk to it directly." />
                            <ul className="flex flex-col px-2" data-testid="agents-subthreads">
                                {agents.subThreads.map((subThread) => (
                                    <SubThreadRow key={subThread.id} subThread={subThread} />
                                ))}
                            </ul>
                        </>
                    )}
                </div>
                <div className={cn('min-h-0 min-w-0 flex-1 flex-col', selected === null ? 'hidden @2xl:flex' : 'flex')} data-testid="agents-detail-pane">
                    {selected === null ? (
                        <p className="m-auto px-6 text-center text-[13px] text-muted" data-testid="agents-detail-empty">
                            {isEmpty ? 'The log of each agent shows here.' : 'Pick an agent to see what it is doing.'}
                        </p>
                    ) : (
                        <AgentDetail
                            key={selected.agent.id}
                            agent={selected.agent}
                            parent={selected.parent}
                            modelLabels={modelLabels}
                            localMessages={sentMessages[selected.agent.id] ?? []}
                            onSent={(message) => setSentMessages((previous) => ({ ...previous, [selected.agent.id]: [...(previous[selected.agent.id] ?? []), message] }))}
                            onBack={() => agents.select(null)}
                        />
                    )}
                </div>
            </div>
        </div>
    );
}
