import { useState } from 'react';
import { DisabledActions } from '@/components/threads/teleport/DisabledActions';
import { HeldEventsList } from '@/components/threads/teleport/HeldEventsList';
import { TeleportNotice } from '@/components/threads/teleport/TeleportNotice';
import { TeleportToComputerDialog } from '@/components/threads/teleport/TeleportToComputerDialog';
import { TeleportToYakDialog } from '@/components/threads/teleport/TeleportToYakDialog';
import type { TeleportData, ThreadData } from '@/types/threads';

/** Everything the teleport track adds to the conversation slot of the thread page. */
export function TeleportConversationNotices({ thread, teleport }: { thread: ThreadData; teleport: TeleportData }) {
    const [dialog, setDialog] = useState<'down' | 'up' | null>(null);
    const close = () => setDialog(null);

    return (
        <div className="flex flex-col gap-3 px-4 py-3 sm:px-8">
            <TeleportNotice thread={thread} teleport={teleport} onShowCommand={() => setDialog('down')} onTeleportToYak={() => setDialog('up')} />
            {teleport.state === 'on_device' && <DisabledActions teleport={teleport} />}
            {teleport.state === 'on_device' && <HeldEventsList teleport={teleport} />}
            <TeleportToComputerDialog thread={thread} teleport={teleport} open={dialog === 'down'} onClose={close} />
            <TeleportToYakDialog thread={thread} teleport={teleport} open={dialog === 'up'} onClose={close} />
        </div>
    );
}
