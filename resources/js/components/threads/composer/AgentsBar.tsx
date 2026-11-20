import { Button } from '@geocodio/console-ui';
import { useAgents } from '@/threads/panels/agents/AgentsContext';
import { runningSummary } from '@/threads/panels/agents/agentsState';
import { StopAllControl } from '@/threads/panels/agents/StopControls';

/** The row on top of the composer while Claude's agents or workflows run: how many, a way to see them, and Stop all. */
export function AgentsBar() {
    const agents = useAgents();
    const summary = agents === null ? null : runningSummary(agents.running);

    if (agents === null || summary === null) {
        return null;
    }

    // The composer opens its tools row when something inside it takes focus; on a phone that moves this bar between the
    // press and the release of a tap, so focus here stays here.
    return (
        <div onFocus={(event) => event.stopPropagation()} className="flex flex-wrap items-center gap-x-3 gap-y-1.5 border-b border-hair px-3.5 py-2.5 text-[13px] text-body max-sm:basis-full" data-testid="agents-bar">
            <span className="size-2 shrink-0 animate-pulse rounded-pill bg-info motion-reduce:animate-none" aria-hidden />
            <span className="min-w-0 flex-1" role="status" data-testid="agents-bar-summary">
                {summary}
            </span>
            <Button variant="link" onClick={() => agents.openPanel()} data-testid="agents-bar-view">
                View agents
            </Button>
            {agents.canDrive && <StopAllControl threadId={agents.threadId} runningCount={agents.running.agents + agents.running.workflows} />}
        </div>
    );
}
