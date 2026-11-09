import { Button, cn } from '@geocodio/console-ui';
import { useRouterAction } from '@/lib/useRouterAction';
import { continueMethod } from '@/routes/tasks';
import type { ThreadPanelData } from '@/types/threads';

const SANDBOX: Record<ThreadPanelData['sandboxState'], { label: string; dot: string }> = {
    running: { label: 'Sandbox running', dot: 'bg-ok' },
    paused: { label: 'Sandbox paused', dot: 'bg-warn' },
    stopped: { label: 'Sandbox stopped', dot: 'bg-idle' },
    none: { label: 'No sandbox yet', dot: 'bg-idle' },
};

const BLOCK_TITLES: Record<string, string> = {
    no_valid_account: 'No valid account',
    invalid_token: 'Invalid token',
    usage_limit: 'Usage limit reached',
    waiting_for_sandbox: 'Waiting for a free sandbox',
};

/** The thread's sandbox, account and model, with the block or guard stop that holds it. */
export function ThreadPanel({ taskId, panel }: { taskId: number; panel: ThreadPanelData }) {
    const action = useRouterAction();
    const sandbox = SANDBOX[panel.sandboxState];
    return (
        <section className="flex flex-col gap-3 rounded-card border border-hair-strong bg-panel px-4 py-3 shadow-card" data-testid="thread-panel">
            <div className="flex flex-wrap items-center gap-x-3 gap-y-2 text-[13px]">
                <span className="inline-flex items-center gap-2 font-medium">
                    <span className={cn('h-2 w-2 rounded-pill', sandbox.dot)} aria-hidden="true" />
                    {sandbox.label}
                </span>
                <span className="text-muted" data-testid="thread-account">
                    Account: {panel.account?.name ?? 'none'}
                    {panel.account?.isSystem ? ' (system)' : ''}
                </span>
                <span className="text-muted">Model: {panel.model || 'default'}</span>
            </div>

            {panel.blocked && (
                <div className="flex flex-col gap-2 rounded-md bg-fail/10 px-3 py-2 text-[13px]" data-testid="thread-blocked" data-reason={panel.blocked.reason}>
                    <div className="flex items-center gap-2 font-medium">
                        <span className="h-2 w-2 rounded-pill bg-fail" aria-hidden="true" />
                        {BLOCK_TITLES[panel.blocked.reason]}
                    </div>
                    <p className="leading-normal">{panel.blocked.message}</p>
                </div>
            )}

            {panel.guardStop && (
                <div className="flex flex-col gap-2 rounded-md bg-warn/10 px-3 py-2 text-[13px]" data-testid="thread-guard-stop">
                    <div className="flex items-center gap-2 font-medium">
                        <span className="h-2 w-2 rounded-pill bg-warn" aria-hidden="true" />
                        {panel.guardStop.reason === 'assistant_turns'
                            ? `Stopped after ${panel.guardStop.limit} assistant turns`
                            : `Stopped after ${panel.guardStop.limit} minutes`}
                    </div>
                    <p className="leading-normal">Yak stopped this turn so it cannot run away. Continue starts a fresh turn with its own limits.</p>
                    {panel.canContinue && (
                        <div>
                            <Button
                                variant="primary"
                                pending={action.isPending('continue')}
                                pendingLabel="Continuing…"
                                onClick={() => action.run('continue', 'post', continueMethod.url(taskId))}
                                data-testid="thread-continue"
                            >
                                Continue
                            </Button>
                        </div>
                    )}
                </div>
            )}
        </section>
    );
}
