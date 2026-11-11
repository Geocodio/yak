import { router, usePage } from '@inertiajs/react';
import { Button, Dialog } from '@geocodio/console-ui';
import { ExternalLink } from 'lucide-react';
import { useState } from 'react';
import { PrivatePublishGate } from '@/components/threads/share/PrivatePublishGate';
import { CommandBlock } from '@/components/threads/teleport/CommandBlock';
import { destroy, store } from '@/routes/threads/teleport-request';
import type { TeleportData, ThreadData } from '@/types/threads';

/**
 * Teleport a Yak-owned thread to the person's computer. The dialog only shows the
 * command; pressing Done marks the thread as waiting, nothing changes until the command runs.
 */
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
        <Dialog open={open} onOpenChange={(next) => !next && onClose()} title="Teleport to your computer" width="w-[min(560px,100vw)]" data-testid="teleport-dialog">
            <div className="flex flex-col gap-4 p-5">
                <p className="text-[13px] text-muted">Run this in a terminal on your computer, in the project checkout.</p>
                <CommandBlock command={teleport.command} testId="teleport-command" />
                <a
                    href={teleport.link}
                    className="inline-flex w-fit items-center gap-1.5 text-[13px] text-accent-text hover:underline"
                    data-testid="teleport-link"
                >
                    <ExternalLink size={13} />
                    Open in terminal
                </a>

                {!teleport.hasDevices && (
                    <p className="text-[13px] text-muted">You have not signed in to the Yak command line on any computer yet. Run <code className="font-mono">yak login</code> first.</p>
                )}

                {teleport.runningTurn && (
                    <fieldset className="flex flex-col gap-2 rounded-lg bg-warn-soft p-3 text-[13px]" data-testid="teleport-running-turn">
                        <legend className="sr-only">Running turn</legend>
                        <p className="font-medium text-body">Claude is in the middle of a turn.</p>
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

                <details className="text-[13px] text-muted">
                    <summary className="cursor-pointer text-body">What happens when you run it</summary>
                    <ol className="mt-2 list-decimal space-y-1 pl-5">
                        <li>Yak finishes or stops its turn and saves any uncommitted work to the thread branch.</li>
                        <li>Yak pushes the branch and the conversation to your computer.</li>
                        <li>Your computer checks out the branch and Claude continues there.</li>
                    </ol>
                </details>

                <p className="text-[13px] text-muted">Nothing changes until you run the command.</p>

                <div className="flex justify-end gap-2">
                    <Button variant="tertiary" onClick={cancel} data-testid="teleport-cancel">
                        {teleport.state === 'waiting' ? 'Cancel teleport' : 'Close'}
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
