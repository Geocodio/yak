import { router, usePage } from '@inertiajs/react';
import { Button, Dialog } from '@geocodio/console-ui';
import { Terminal, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import { PrivatePublishGate } from '@/components/threads/share/PrivatePublishGate';
import { CommandBlock } from '@/components/threads/teleport/CommandBlock';
import { destroy, store } from '@/routes/threads/teleport-request';
import type { TeleportData, ThreadData } from '@/types/threads';

/**
 * Teleport a Yak-owned thread to the person's computer. The dialog only shows the
 * command; pressing Done marks the thread as waiting, nothing changes until the command runs.
 */
const TELEPORT_DOCS = 'https://geocodio.github.io/yak/teleport/';
const INSTALL_DOCS = 'https://geocodio.github.io/yak/teleport/install/';
const docsLinkClass = 'text-accent-text hover:underline';

export function TeleportToComputerDialog({ thread, teleport, open, onClose }: { thread: ThreadData; teleport: TeleportData; open: boolean; onClose: () => void }) {
    const [runningTurn, setRunningTurn] = useState<'wait' | 'stop'>('wait');
    const [processing, setProcessing] = useState(false);
    const { publishWarning } = usePage<{ publishWarning: boolean } & Record<string, unknown>>().props;

    const done = () => {
        if (teleport.state === 'waiting') {
            onClose();
            return;
        }
        router.post(
            store.url(thread.id),
            { ...(teleport.runningTurn ? { running_turn: runningTurn } : {}), ...(publishWarning ? { acknowledged_private_publish: true } : {}) },
            { preserveScroll: true, onStart: () => setProcessing(true), onFinish: () => setProcessing(false), onSuccess: onClose },
        );
    };

    const cancel = () => {
        if (teleport.state === 'waiting') {
            router.delete(destroy.url(thread.id), { preserveScroll: true, onSuccess: onClose });
            return;
        }
        onClose();
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => !next && onClose()}
            title="Teleport to computer"
            description="Continue this thread in Claude Code on one of your computers. Only committed work travels, through GitHub."
            width="w-[min(560px,100vw)]"
            data-testid="teleport-dialog"
        >
            <div className="flex flex-col gap-4 p-5 text-[13px]">
                <div className="flex flex-col gap-1.5">
                    <p className="font-medium">
                        In a terminal, inside your checkout of <code className="font-mono text-[12px]">{thread.repo}</code>
                    </p>
                    <CommandBlock command={teleport.command} testId="teleport-command" />
                </div>

                <div className="flex flex-col gap-1.5">
                    <p className="font-medium">Or open it in one click</p>
                    <div className="flex flex-wrap items-center gap-2">
                        <a href={teleport.link} className="inline-flex" data-testid="teleport-link">
                            <Button variant="secondary" icon={<Terminal size={13} />} tabIndex={-1}>
                                Open in terminal
                            </Button>
                        </a>
                        <code className="min-w-0 truncate font-mono text-[11.5px] text-muted">{teleport.link}</code>
                    </div>
                    <p className="text-[12px] leading-relaxed text-muted">
                        Needs the Yak plugin. It asks once where <code className="font-mono text-[11.5px]">{thread.repo}</code> lives on this computer. No plugin yet?{' '}
                        <a href={INSTALL_DOCS} target="_blank" rel="noreferrer" className={docsLinkClass}>
                            Install it
                        </a>
                    </p>
                </div>

                {!teleport.hasDevices && (
                    <p className="text-[13px] text-muted">You have not signed in to the Yak command line on any computer yet. Run <code className="font-mono">yak login</code> first.</p>
                )}

                {teleport.runningTurn && (
                    <div className="rounded-lg bg-warn-soft p-3 text-[12.5px] leading-relaxed" data-testid="running-note">
                        <div className="flex items-center gap-1.5 font-semibold">
                            <TriangleAlert size={13} className="text-warn" aria-hidden="true" />
                            Claude is in the middle of a turn
                        </div>
                        <p className="mt-1 text-muted">The command asks whether to wait for it to finish (the default) or stop it now. Stopping keeps the work done so far.</p>
                    </div>
                )}

                {teleport.runningTurn && (
                    <fieldset className="flex flex-col gap-2 rounded-lg bg-warn-soft p-3" data-testid="teleport-running-turn">
                        <legend className="sr-only">Running turn</legend>
                        <label className="flex items-start gap-2">
                            <input type="radio" name="running_turn" checked={runningTurn === 'wait'} onChange={() => setRunningTurn('wait')} />
                            <span>Wait for the turn to finish, then move the thread.</span>
                        </label>
                        <label className="flex items-start gap-2">
                            <input type="radio" name="running_turn" checked={runningTurn === 'stop'} onChange={() => setRunningTurn('stop')} />
                            <span>Stop the turn. Yak saves the work so far, then moves the thread.</span>
                        </label>
                    </fieldset>
                )}

                <details className="rounded-lg bg-panel-2 p-3 text-[12.5px] leading-relaxed text-muted">
                    <summary className="cursor-pointer font-medium text-body">What happens when you run it</summary>
                    <ol className="mt-1.5 list-decimal space-y-0.5 pl-4">
                        <li>
                            Yak commits any unsaved sandbox work as &ldquo;WIP: teleport to computer&rdquo; and pushes <code className="font-mono text-[11.5px]">{thread.branch}</code>.
                        </li>
                        <li>Your computer fetches and fast-forwards. It never merges or rebases.</li>
                        <li>If your checkout has changes, you choose what happens to them first.</li>
                    </ol>
                </details>

                <p className="text-[12px] text-muted">
                    Nothing changes until you run the command.{' '}
                    <a href={TELEPORT_DOCS} target="_blank" rel="noreferrer" className={docsLinkClass}>
                        How teleport works
                    </a>
                </p>

                <div className="flex justify-end gap-2">
                    <Button variant="tertiary" onClick={cancel} data-testid="teleport-cancel">
                        Cancel teleport
                    </Button>
                    <PrivatePublishGate required={publishWarning && teleport.state !== 'waiting'} onConfirm={done}>
                        {(confirm) => (
                            <Button variant="primary" pending={processing} onClick={confirm} data-testid="teleport-done">
                                Done
                            </Button>
                        )}
                    </PrivatePublishGate>
                </div>
            </div>
        </Dialog>
    );
}
