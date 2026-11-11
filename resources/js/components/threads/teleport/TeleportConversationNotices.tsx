import { DisabledActions } from '@/components/threads/teleport/DisabledActions';
import { HeldEventsList } from '@/components/threads/teleport/HeldEventsList';
import { TeleportNotice } from '@/components/threads/teleport/TeleportNotice';
import type { TeleportData, ThreadData } from '@/types/threads';

/** Everything the teleport track adds to the conversation slot of the thread page. */
export function TeleportConversationNotices({ thread, teleport }: { thread: ThreadData; teleport: TeleportData }) {
    return (
        <div className="flex flex-col gap-3 px-4 py-3 sm:px-8">
            <TeleportNotice thread={thread} teleport={teleport} />
            {teleport.state === 'on_device' && <DisabledActions teleport={teleport} />}
            {teleport.state === 'on_device' && <HeldEventsList teleport={teleport} />}
        </div>
    );
}
