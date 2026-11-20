import { useAgents } from './AgentsContext';
import { agentsTabCount } from './agentsState';

/** What runs now, or everything the thread has started once nothing runs; hidden at zero. */
export function AgentsBadge() {
    const agents = useAgents();
    const count = agents === null ? 0 : agentsTabCount(agents.rows, agents.subThreads.length);

    return count === 0 ? null : <>{count}</>;
}
