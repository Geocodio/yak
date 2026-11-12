import type { ThreadMessage } from '@/types/threads';
import type { ConversationItem } from './conversationItem';

export type ConversationEntry = { type: 'person'; message: ThreadMessage; pending: boolean } | { type: 'event'; item: ConversationItem };

/** Remembers every uuid that was ever read, so a reconnect replay can never turn a read message back into a pending one. */
export function createReadTracker(): (readUuids: string[]) => string[] {
    const known = new Set<string>();

    return (readUuids) => {
        readUuids.forEach((uuid) => known.add(uuid));

        return [...known];
    };
}

function sameTime(a: ThreadMessage, b: ThreadMessage): number {
    return a.sentAt.localeCompare(b.sentAt);
}

/**
 * Interleaves the person's messages with the model's events.
 * - messages and items are de-duplicated (by uuid or id, and by item key);
 * - an unread message is greyed and sits at the end;
 * - a read message with `readAfterSequence` follows the last event up to that sequence;
 * - a message read live follows the event at its `readSequences` entry, or the answer when none is known, at the end of the events;
 * - a message the server already marks read without a sequence is earlier history and keeps send order at the top.
 */
export function mergeConversation(messages: ThreadMessage[], readUuids: string[], items: ConversationItem[], readSequences: Record<string, number> = {}): ConversationEntry[] {
    const live = new Set(readUuids);
    const unique = new Map<string, ThreadMessage>();
    messages.forEach((message) => unique.set(message.uuid ?? message.id, message));

    const visible = [...unique.values()].filter((message) => message.state !== 'cancelled').sort(sameTime);
    const isRead = (message: ThreadMessage) => message.state === 'read' || (message.uuid !== null && live.has(message.uuid));
    const anchorOf = (message: ThreadMessage): number | null => message.readAfterSequence ?? (message.uuid === null ? null : (readSequences[message.uuid] ?? null));

    const history = visible.filter((message) => message.state === 'read' && message.readAfterSequence === null);
    const anchored = visible.filter((message) => isRead(message) && anchorOf(message) !== null).sort((a, b) => anchorOf(a)! - anchorOf(b)!);
    const justRead = visible.filter((message) => isRead(message) && message.state !== 'read' && anchorOf(message) === null);
    const pending = visible.filter((message) => !isRead(message));

    const events = new Map<string, ConversationItem>();
    items.forEach((item) => events.set(item.key, item));
    const ordered = [...events.values()].sort((a, b) => a.sequence - b.sequence);

    const person = (message: ThreadMessage, isPending: boolean): ConversationEntry => ({ type: 'person', message, pending: isPending });
    const entries: ConversationEntry[] = history.map((message) => person(message, false));
    let next = 0;

    ordered.forEach((item) => {
        entries.push({ type: 'event', item });
        while (next < anchored.length && anchorOf(anchored[next])! <= item.sequence) {
            entries.push(person(anchored[next++], false));
        }
    });

    anchored.slice(next).forEach((message) => entries.push(person(message, false)));
    justRead.forEach((message) => entries.push(person(message, false)));
    pending.forEach((message) => entries.push(person(message, true)));

    return entries;
}
