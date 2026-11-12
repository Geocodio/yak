import { Button, Dialog } from '@geocodio/console-ui';
import { useState } from 'react';
import { useDocsUrl } from '@/lib/docsUrl';
import { CommandBlock } from '@/components/threads/teleport/CommandBlock';
import type { TeleportData, ThreadData } from '@/types/threads';

/** Teleport a device-owned thread back to Yak. The command runs inside Claude Code on the computer. */
export function TeleportToYakDialog({ thread, teleport, open, onClose }: { thread: ThreadData; teleport: TeleportData; open: boolean; onClose: () => void }) {
    const teleportDocs = useDocsUrl('teleport/');
    const [sendHeld, setSendHeld] = useState(true);
    const hasHeld = teleport.heldEvents.length > 0;
    const device = teleport.ownerDevice?.name ?? 'your computer';

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => !next && onClose()}
            title="Teleport to Yak"
            description={`This thread is on ${device}. Bring it back from the Claude Code session there.`}
            width="w-[min(560px,100vw)]"
            data-testid="teleport-dialog"
        >
            <div className="flex flex-col gap-4 p-5 text-[13px]">
                <div className="flex flex-col gap-1.5">
                    <p className="font-medium">In the Claude Code session on {device}</p>
                    <CommandBlock command="/yak teleport" testId="teleport-command" prefix="" />
                    <p className="text-[12px] text-muted">Claude waits on Yak for your next message.</p>
                </div>

                <div className="flex flex-col gap-1.5">
                    <p className="font-medium">To start a turn right away</p>
                    <CommandBlock command={'/yak teleport "Fix the lint failure on the PR"'} testId="teleport-command-with-message" prefix="" />
                </div>

                {hasHeld && (
                    <label className="flex items-start gap-2">
                        <input type="checkbox" checked={sendHeld} onChange={(event) => setSendHeld(event.target.checked)} />
                        <span>Send the held events to Claude as the first message ({teleport.heldEvents.length} waiting)</span>
                    </label>
                )}

                <p className="rounded-lg bg-panel-2 p-3 text-[12.5px] leading-relaxed text-muted">
                    The command commits your work first (it asks about anything uncommitted), pushes <code className="font-mono text-[11.5px]">{thread.branch}</code> and uploads the conversation. After that the session on {device} becomes read-only.{' '}
                    <a href={teleportDocs} target="_blank" rel="noreferrer" className="text-accent-text hover:underline">
                        How teleport works
                    </a>
                </p>

                <div className="flex justify-end">
                    <Button variant="primary" onClick={onClose} data-testid="teleport-done">
                        Done
                    </Button>
                </div>
            </div>
        </Dialog>
    );
}
