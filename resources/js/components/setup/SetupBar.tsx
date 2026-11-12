import { usePage } from '@inertiajs/react';
import { Button, IconButton } from '@geocodio/console-ui';
import { Check, X } from 'lucide-react';
import { useState } from 'react';
import { AccountDialog, type AccountDialogMode } from '@/components/accounts/AccountDialog';
import { TeleportSetupDialog } from '@/components/teleport/TeleportSetupDialog';
import type { SharedProps } from '@/types/shared';
import { isDismissible, resolveSetupBar, type SetupBarState } from './setupBarState';

type OpenDialog = { kind: 'account'; mode: AccountDialogMode } | { kind: 'teleport' } | null;

function storageKey(userId: number): string {
    return `yak.setupBar.dismissed.${userId}`;
}

function readDismissed(userId: number): string[] {
    try {
        const stored: unknown = JSON.parse(window.localStorage.getItem(storageKey(userId)) ?? '[]');

        return Array.isArray(stored) ? stored.filter((value): value is string => typeof value === 'string') : [];
    } catch {
        return [];
    }
}

function writeDismissed(userId: number, dismissed: string[]): void {
    try {
        window.localStorage.setItem(storageKey(userId), JSON.stringify(dismissed));
    } catch {
        // Dismissal then lasts until the next page load, which is acceptable.
    }
}

const BAR = 'flex flex-wrap items-center gap-x-3.5 gap-y-2 border-b border-hair px-3.5 py-2 text-[13px] max-lg:pl-12';
const OPEN_RING = <span className="size-2 rounded-pill border-[1.5px] border-muted" aria-hidden="true" />;

/** One slim bar above the page that says what is left to set up. The dialogs open in place. */
export function SetupBar() {
    const { setup, auth } = usePage<SharedProps>().props;
    const userId = auth.user?.id ?? 0;
    const [dismissed, setDismissed] = useState<string[]>(() => readDismissed(userId));
    const [dialog, setDialog] = useState<OpenDialog>(null);
    const state = resolveSetupBar(setup, dismissed);

    const dismiss = (current: SetupBarState) => {
        const next = [...dismissed, current];
        setDismissed(next);
        writeDismissed(userId, next);
    };
    const openTeleport = () => setDialog({ kind: 'teleport' });
    const dismissButton = state !== null && isDismissible(state) && (
        <IconButton label="Dismiss" onClick={() => dismiss(state)} data-testid="setup-bar-dismiss"><X size={14} aria-hidden="true" /></IconButton>
    );

    return (
        <>
            {state !== null && setup !== null && (
                <section aria-label="Setup" data-testid="setup-bar" data-state={state} className={state === 'yak-account' || state === 'broken-account' ? `${BAR} bg-warn-soft` : `${BAR} bg-panel`}>
                    {state === 'yak-account' && (
                        <>
                            <span className="size-2 shrink-0 rounded-pill bg-warn" aria-hidden="true" />
                            <span className="font-semibold">Yak has no shared Claude account</span>
                            <span className="min-w-[200px] flex-1 text-muted">Sentry alerts, Linear automation and flaky test fixes cannot run until you add one. It takes about a minute.</span>
                            <Button variant="primary" onClick={() => setDialog({ kind: 'account', mode: 'addSystem' })} data-testid="setup-bar-add-yak-account">Add Yak account</Button>
                        </>
                    )}
                    {state === 'broken-account' && setup.brokenAccount && (
                        <>
                            <span className="size-2 shrink-0 rounded-pill bg-warn" aria-hidden="true" />
                            <span className="font-semibold">Your Claude account &ldquo;{setup.brokenAccount.name}&rdquo; stopped working</span>
                            <span className="min-w-[200px] flex-1 text-muted">Claude rejected the token. Your tasks are paused until you paste a new one.</span>
                            <Button variant="primary" onClick={() => setDialog({ kind: 'account', mode: 'rotate' })} data-testid="setup-bar-replace-token">Replace token</Button>
                        </>
                    )}
                    {state === 'both' && (
                        <>
                            <span className="font-semibold">Finish your setup</span>
                            <span className="min-w-[200px] flex-1 text-muted">Run tasks on your own Claude account, and move threads to your computer with Teleport.</span>
                            <Button variant="secondary" icon={OPEN_RING} onClick={() => setDialog({ kind: 'account', mode: 'add' })} data-testid="setup-bar-connect-claude">Connect Claude</Button>
                            <Button variant="secondary" icon={OPEN_RING} onClick={openTeleport} data-testid="setup-bar-set-up-teleport">Set up Teleport</Button>
                        </>
                    )}
                    {state === 'teleport' && (
                        <>
                            <span className="font-semibold">Teleport is not set up</span>
                            <span className="min-w-[200px] flex-1 text-muted">Teleport moves a thread between Yak and Claude Code on your computer. No computer is connected yet.</span>
                            <span className="inline-flex items-center gap-1.5 font-medium text-ok"><Check size={14} aria-hidden="true" />Claude connected</span>
                            <Button variant="primary" onClick={openTeleport} data-testid="setup-bar-set-up-teleport">Set up Teleport</Button>
                        </>
                    )}
                    {state === 'claude' && (
                        <>
                            <span className="font-semibold">Your tasks run on the shared Yak account</span>
                            <span className="min-w-[200px] flex-1 text-muted">Connect your own Claude account so usage counts against your plan.</span>
                            <span className="inline-flex items-center gap-1.5 font-medium text-ok"><Check size={14} aria-hidden="true" />Teleport connected</span>
                            <Button variant="primary" onClick={() => setDialog({ kind: 'account', mode: 'add' })} data-testid="setup-bar-connect-claude">Connect Claude</Button>
                        </>
                    )}
                    {dismissButton}
                </section>
            )}

            {setup !== null && dialog?.kind === 'account' && (
                <AccountDialog
                    key={dialog.mode}
                    mode={dialog.mode}
                    account={dialog.mode === 'rotate' ? setup.brokenAccount ?? undefined : undefined}
                    models={setup.models}
                    hasDefault={setup.hasOwnAccount}
                    onClose={() => setDialog(null)}
                    onSetUpTeleport={openTeleport}
                />
            )}
            {dialog?.kind === 'teleport' && <TeleportSetupDialog open onClose={() => setDialog(null)} />}
        </>
    );
}
