/**
 * Where each walkthrough version belongs: after the last event entry at or before the event its recording ended at.
 * A version with no anchor, or none of whose events are loaded, goes at the end. `after` is keyed by the entry's index.
 */
export function placeWalkthroughs<Version extends { anchorSequence: number | null }>(entries: ReadonlyArray<{ type: string; item?: { sequence: number } }>, versions: Version[]): { after: Map<number, Version[]>; trailing: Version[] } {
    const after = new Map<number, Version[]>();
    const trailing: Version[] = [];

    versions.forEach((version) => {
        let index = -1;

        entries.forEach((entry, position) => {
            if (version.anchorSequence !== null && entry.type === 'event' && entry.item !== undefined && entry.item.sequence <= version.anchorSequence) {
                index = position;
            }
        });

        if (index === -1) {
            trailing.push(version);
        } else {
            after.set(index, [...(after.get(index) ?? []), version]);
        }
    });

    return { after, trailing };
}
