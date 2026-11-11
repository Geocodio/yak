import { Button, Dialog } from '@geocodio/console-ui';
import { ExternalLink } from 'lucide-react';
import { useState } from 'react';
import { CommandBlock } from '@/components/threads/teleport/CommandBlock';
import type { TeleportData } from '@/types/threads';

/** Teleport a device-owned thread back to Yak. The command runs inside Claude Code on the computer. */
export function TeleportToYakDialog({ teleport, open, onClose }: { teleport: TeleportData; open: boolean; onClose: () => void }) {
    const [sendHeld, setSendHeld] = useState(true);
    const hasHeld = teleport.heldEvents.length > 0;
    const command = '/yak teleport "<message>"';

    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()} title="Teleport to Yak" width="w-[min(560px,100vw)]" data-testid="teleport-dialog">
            <div className="flex flex-col gap-4 p-5">
                <p className="text-[13px] text-muted">Run this inside Claude Code on {teleport.ownerDevice?.name ?? 'your computer'}. Replace the message with what Yak should do next.</p>
                <CommandBlock command={command} testId="teleport-command" />
                <a href={teleport.link} className="inline-flex w-fit items-center gap-1.5 text-[13px] text-accent-text hover:underline" data-testid="teleport-link">
                    <ExternalLink size={13} />
                    Open in terminal
                </a>

                {hasHeld && (
                    <label className="flex items-start gap-2 text-[13px]">
                        <input type="checkbox" checked={sendHeld} onChange={(event) => setSendHeld(event.target.checked)} />
                        <span>Send the held events to Claude as the first message ({teleport.heldEvents.length} waiting)</span>
                    </label>
                )}

                <ol className="list-decimal space-y-1 pl-5 text-[13px] text-muted">
                    <li>Your computer pushes the branch and the conversation to Yak.</li>
                    <li>Yak prepares a sandbox from them.</li>
                    <li>Claude continues in Yak and your computer stops owning the thread.</li>
                </ol>

                <p className="text-[13px] text-muted">Nothing changes until you run the command.</p>

                <div className="flex justify-end">
                    <Button variant="primary" onClick={onClose} data-testid="teleport-done">
                        Done
                    </Button>
                </div>
            </div>
        </Dialog>
    );
}
