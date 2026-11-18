import type { ThreadMessage } from '@/types/threads';

const QUEUED_ID_PREFIX = 'queued-message-';

/** The messages saved but not yet handed to a turn wait in the composer's list, so the conversation leaves them out. */
export function withoutQueuedMessages(messages: ThreadMessage[]): ThreadMessage[] {
    return messages.filter((message) => !message.id.startsWith(QUEUED_ID_PREFIX));
}
