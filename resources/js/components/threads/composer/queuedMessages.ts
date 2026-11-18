import type { QueuedRow } from '@/components/shared/QueuedMessages';
import type { ThreadMessage } from '@/types/threads';

const QUEUED_ID_PREFIX = 'queued-message-';

/**
 * The messages saved but not yet handed to a turn leave the conversation and wait in the composer's list;
 * once Yak takes one in, it returns to the conversation under its turn message.
 */
export function splitQueuedMessages(messages: ThreadMessage[]): { queued: QueuedRow[]; conversation: ThreadMessage[] } {
    const queued: QueuedRow[] = [];
    const conversation: ThreadMessage[] = [];

    for (const message of messages) {
        if (!message.id.startsWith(QUEUED_ID_PREFIX)) {
            conversation.push(message);
            continue;
        }

        queued.push({
            id: Number(message.id.slice(QUEUED_ID_PREFIX.length)),
            text: message.text,
            mode: message.mode ?? 'queue',
            source: 'thread',
            authorName: message.authorName,
            canSteer: true,
            attachments: message.attachments,
            canEditText: message.canEditText,
        });
    }

    return { queued, conversation };
}
