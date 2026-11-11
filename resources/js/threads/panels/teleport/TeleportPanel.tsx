import { usePage } from '@inertiajs/react';
import { Button } from '@geocodio/console-ui';
import { Monitor } from 'lucide-react';
import { useState } from 'react';
import { formatTime } from '@/components/shell/formatTime';
import { TeleportToComputerDialog } from '@/components/threads/teleport/TeleportToComputerDialog';
import { ownerLabel } from '@/components/threads/teleport/TeleportNotice';
import { TeleportToYakDialog } from '@/components/threads/teleport/TeleportToYakDialog';
import type { PanelProps } from '@/threads/panels';
import type { ThreadPageData } from '@/types/threads';

export function TeleportPanel({ thread }: PanelProps) {
    const { teleport } = usePage<ThreadPageData & Record<string, unknown>>().props;
    const [open, setOpen] = useState(false);
    const onDevice = teleport.state === 'on_device';

    return (
        <div className="flex flex-col gap-4" data-testid="teleport-panel">
            <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-[13px]">
                <dt className="text-muted">Owner</dt>
                <dd className="text-body">{onDevice ? ownerLabel(teleport) : 'Yak'}</dd>
                {teleport.since && (
                    <>
                        <dt className="text-muted">Since</dt>
                        <dd className="text-body">{formatTime(teleport.since)}</dd>
                    </>
                )}
                {teleport.accountName && (
                    <>
                        <dt className="text-muted">Account</dt>
                        <dd className="text-body">{teleport.accountName}</dd>
                    </>
                )}
                {onDevice && (
                    <>
                        <dt className="text-muted">Held events</dt>
                        <dd className="text-body">{teleport.heldEvents.length}</dd>
                    </>
                )}
            </dl>

            {teleport.environmentNote && (
                <details className="text-[13px] text-muted">
                    <summary className="cursor-pointer text-body">Environment note</summary>
                    <pre className="mt-2 whitespace-pre-wrap font-sans">{teleport.environmentNote}</pre>
                </details>
            )}

            {teleport.canTeleport && teleport.state !== 'teleporting' && (
                <Button variant="secondary" icon={<Monitor size={14} />} onClick={() => setOpen(true)} data-testid="teleport-button">
                    {onDevice ? 'Teleport to Yak' : 'Teleport to your computer'}
                </Button>
            )}

            {onDevice ? (
                <TeleportToYakDialog teleport={teleport} open={open} onClose={() => setOpen(false)} />
            ) : (
                <TeleportToComputerDialog thread={thread} teleport={teleport} open={open} onClose={() => setOpen(false)} />
            )}
        </div>
    );
}
