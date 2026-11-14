/** Index at which the "moved between your computer and Yak" divider belongs: before the first message sent after the move, or at the end. */
export function switchPosition(entries: ReadonlyArray<{ type: string; message?: { sentAt: string } }>, switchedAt: string): number {
    const movedAt = Date.parse(switchedAt);
    const index = entries.findIndex((entry) => entry.type === 'person' && entry.message !== undefined && Date.parse(entry.message.sentAt) > movedAt);

    return index === -1 ? entries.length : index;
}
