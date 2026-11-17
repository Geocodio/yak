import type { ThreadRunSummary } from '@/types/threads';

type Placeable = { type: string; item?: { sequence: number } };

/**
 * Where each run's footer (its milestones and "Full log") belongs: after the last event entry at or before the
 * sequence its answer ended at when the page loaded. The newest run keeps growing while events stream in, so its
 * footer follows the events that come after that sequence, up to the next message from a person. It stays hidden
 * while the thread is still working on that run. A run none of whose events are loaded gets no footer. The result is
 * keyed by the entry's index and lists the runs in order.
 */
export function placeRunFooters(entries: ReadonlyArray<Placeable>, runs: ThreadRunSummary[], isWorking = false): Map<number, ThreadRunSummary[]> {
    const placed = new Map<number, ThreadRunSummary[]>();

    runs.forEach((run, position) => {
        let index = -1;

        entries.forEach((entry, at) => {
            if (entry.type === 'event' && entry.item !== undefined && entry.item.sequence <= run.afterSequence) {
                index = at;
            }
        });

        if (index === -1) {
            return;
        }

        if (position === runs.length - 1) {
            while (index + 1 < entries.length && entries[index + 1].type === 'event') {
                index += 1;
            }
            if (isWorking && index === entries.length - 1) {
                return;
            }
        }

        placed.set(index, [...(placed.get(index) ?? []), run]);
    });

    return placed;
}
