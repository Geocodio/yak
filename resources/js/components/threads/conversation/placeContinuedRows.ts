type Placeable = { type: string; item?: { sequence: number } };
type RunEnd = { runId: string; afterSequence: number };

/**
 * Where each "agent finished, Claude continued" row belongs: before the first event of the run that the agent's end
 * started, which is the first event after the run before it ended. A run none of whose events are loaded gets no row.
 * The result is keyed by the entry's index.
 */
export function placeContinuedRows<T>(entries: ReadonlyArray<Placeable>, runs: RunEnd[], byRun: Map<number, T[]>): Map<number, T[]> {
    const placed = new Map<number, T[]>();
    const ordered = [...runs].sort((a, b) => Number(a.runId) - Number(b.runId));

    ordered.forEach((run, position) => {
        const continued = byRun.get(Number(run.runId));

        if (continued === undefined) {
            return;
        }

        const boundary = position === 0 ? 0 : ordered[position - 1].afterSequence;
        const index = entries.findIndex((entry) => entry.type === 'event' && entry.item !== undefined && entry.item.sequence > boundary);

        if (index !== -1) {
            placed.set(index, [...(placed.get(index) ?? []), ...continued]);
        }
    });

    return placed;
}
