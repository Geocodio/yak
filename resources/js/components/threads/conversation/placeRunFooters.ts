import type { ThreadRunSummary } from '@/types/threads';

/**
 * Where each run's footer (its milestones and "Full log") belongs: after the last event entry at or before the
 * sequence its answer ends at. A run none of whose events are loaded gets no footer. The result is keyed by the
 * entry's index and lists the runs in order.
 */
export function placeRunFooters(entries: ReadonlyArray<{ type: string; item?: { sequence: number } }>, runs: ThreadRunSummary[]): Map<number, ThreadRunSummary[]> {
    const placed = new Map<number, ThreadRunSummary[]>();

    runs.forEach((run) => {
        let index = -1;

        entries.forEach((entry, position) => {
            if (entry.type === 'event' && entry.item !== undefined && entry.item.sequence <= run.afterSequence) {
                index = position;
            }
        });

        if (index !== -1) {
            placed.set(index, [...(placed.get(index) ?? []), run]);
        }
    });

    return placed;
}
