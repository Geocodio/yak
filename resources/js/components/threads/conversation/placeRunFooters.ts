import type { ThreadRunSummary } from '@/types/threads';

type Placeable = { type: string; item?: { sequence: number } };

/**
 * Where each run's footer (its milestones and "Full log") belongs: after the last event entry at or before the
 * sequence its answer ends at. A run that is still active has no footer, whatever the composer says and wherever
 * messages sit, because its events are still arriving; the footer shows once the run has ended and the page props
 * say so. A run none of whose events are loaded gets no footer. The result is keyed by the entry's index and lists
 * the runs in order.
 */
export function placeRunFooters(entries: ReadonlyArray<Placeable>, runs: ThreadRunSummary[]): Map<number, ThreadRunSummary[]> {
    const placed = new Map<number, ThreadRunSummary[]>();

    runs.filter((run) => !run.isActive).forEach((run) => {
        let index = -1;

        entries.forEach((entry, at) => {
            if (entry.type === 'event' && entry.item !== undefined && entry.item.sequence <= run.afterSequence) {
                index = at;
            }
        });

        if (index !== -1) {
            placed.set(index, [...(placed.get(index) ?? []), run]);
        }
    });

    return placed;
}
