import { Button, Dialog, cn } from '@geocodio/console-ui';
import { useState } from 'react';
import { useRouterAction } from '@/lib/useRouterAction';
import { continueMethod } from '@/routes/tasks';
import { update as updateAccount } from '@/routes/tasks/thread/account';
import { update as updateModel } from '@/routes/tasks/thread/model';
import type { ThreadPanelData } from '@/types/threads';

export const SWITCH_WARNING = 'Switching accounts makes the next turn re-read the whole conversation without cache, so it costs more than a normal turn.';

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

const SELECT_CLASS = 'h-7 rounded-md border border-hair-strong bg-panel px-2 text-[12.5px]';

/** The thread's sandbox, account and model, with the block or guard stop that holds it. */
export function ThreadPanel({ taskId, panel }: { taskId: number; panel: ThreadPanelData }) {
    const action = useRouterAction();
    const [switchOpen, setSwitchOpen] = useState(false);
    const [chosen, setChosen] = useState<number | null>(null);
    const sandbox = SANDBOX[panel.sandboxState];
    const canSwitch = panel.switchableAccounts.length > 0;
    const target = chosen ?? panel.switchableAccounts[0]?.id ?? null;

    const confirmSwitch = () =>
        target !== null &&
        action.run('switch', 'put', updateAccount.url(taskId), { claude_account_id: target }, { onSuccess: () => setSwitchOpen(false) });

    const switchButton = (primary: boolean) => (
        <Button variant={primary ? 'primary' : 'secondary'} onClick={() => setSwitchOpen(true)} data-testid="thread-switch-account">
            Switch account
        </Button>
    );

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
                {canSwitch ? (
                    <select
                        aria-label="Model"
                        className={SELECT_CLASS}
                        value={panel.model}
                        onChange={(event) => action.run('model', 'put', updateModel.url(taskId), { model: event.target.value })}
                        data-testid="thread-model"
                    >
                        {[...new Set([panel.model, ...panel.models])].filter(Boolean).map((model) => (
                            <option key={model} value={model}>
                                {model}
                            </option>
                        ))}
                    </select>
                ) : (
                    <span className="text-muted">Model: {panel.model || 'default'}</span>
                )}
                {canSwitch && <span className="ml-auto">{switchButton(false)}</span>}
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

            <Dialog
                open={switchOpen}
                onOpenChange={setSwitchOpen}
                title="Switch account"
                description={SWITCH_WARNING}
                footer={
                    <>
                        <Button variant="tertiary" onClick={() => setSwitchOpen(false)}>
                            Cancel
                        </Button>
                        <Button variant="primary" pending={action.isPending('switch')} pendingLabel="Switching…" onClick={confirmSwitch} data-testid="thread-switch-confirm">
                            Switch account
                        </Button>
                    </>
                }
            >
                <select
                    aria-label="Account"
                    className={cn(SELECT_CLASS, 'w-full')}
                    value={target ?? ''}
                    onChange={(event) => setChosen(Number(event.target.value))}
                    data-testid="thread-switch-select"
                >
                    {panel.switchableAccounts.map((account) => (
                        <option key={account.id} value={account.id}>
                            {account.name}
                            {account.isSystem ? ' (system)' : ''}
                        </option>
                    ))}
                </select>
            </Dialog>
        </section>
    );
}
