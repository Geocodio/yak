import { cn } from '@geocodio/console-ui';
import type { ThreadAgentStatus } from '@/types/thread-agents';

const DOT: Record<ThreadAgentStatus, string> = {
    running: 'bg-info animate-pulse motion-reduce:animate-none',
    completed: 'bg-ok',
    failed: 'bg-fail',
    stopped: 'bg-idle',
};

const LABEL: Record<ThreadAgentStatus, string> = { running: 'Running', completed: 'Finished', failed: 'Failed', stopped: 'Stopped' };

/** A filled status dot, never a stripe. */
export function AgentStatusDot({ status, className }: { status: ThreadAgentStatus; className?: string }) {
    return <span role="img" aria-label={LABEL[status]} className={cn('inline-block size-2 shrink-0 rounded-pill', DOT[status], className)} data-status={status} data-testid="agent-status-dot" />;
}
