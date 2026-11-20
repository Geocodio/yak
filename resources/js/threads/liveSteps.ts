import type { TranscriptEntry } from '../types/thread-events';

/**
 * Claude's own assistant entries after `turnStartSequence`, from the live feed. An agent's entries carry an agentId
 * and are not Claude's steps. `entrySequences[i]` is the event sequence of `entries[i]`.
 */
export function liveStepCount(entries: TranscriptEntry[], entrySequences: number[], turnStartSequence: number): number {
    return entries.filter((entry, index) => entry.role === 'assistant' && entry.agentId === undefined && (entrySequences[index] ?? 0) > turnStartSequence).length;
}

/** The count to show: the stored value can lag the feed, the feed can lag a reload, so the larger one wins. */
export function shownSteps(storedSteps: number, liveSteps: number): number {
    return Math.max(storedSteps, liveSteps);
}
