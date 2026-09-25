import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import type { TranscriptEntry } from '@/types/tasks';

/**
 * Fetches the transcript entry for `selectedLogId` on demand and caches every
 * entry the server sends, so stepping back to one already seen never
 * re-fetches it. A tool call still running is the one exception: its output
 * arrives after the row does, so it is refetched on every visit until output
 * exists. A run/attempt switch (`runKey` changing) invalidates every cached
 * entry -- they belong to a different transcript. Shared by the desktop
 * transcript overlay and the phone log entry sheet.
 */
export function useTranscriptEntry({
    open,
    selectedLogId,
    entry,
    runKey,
}: {
    open: boolean;
    selectedLogId: number | null;
    entry: TranscriptEntry | null | undefined;
    runKey: string;
}): TranscriptEntry | undefined {
    const cacheRef = useRef(new Map<number, TranscriptEntry>());
    const runKeyRef = useRef(runKey);

    // Declared before the fetch effect below so the clear always lands in
    // the same commit before that effect checks the cache for `selectedLogId`.
    useEffect(() => {
        if (runKeyRef.current !== runKey) {
            runKeyRef.current = runKey;
            cacheRef.current.clear();
        }
    }, [runKey]);

    useEffect(() => {
        if (entry?.id != null && !(entry.kind === 'tool' && entry.output === null)) {
            cacheRef.current.set(entry.id, entry);
        }
    }, [entry]);

    // Also depends on `runKey` -- a run/attempt switch while the same log id
    // stays selected clears the cache above but wouldn't otherwise re-run
    // this effect, leaving the pane stuck showing the stale entry (or
    // "Loading entry…" forever if nothing was cached for it before).
    useEffect(() => {
        if (!open || selectedLogId === null || cacheRef.current.has(selectedLogId)) {
            return;
        }
        router.reload({ only: ['transcriptEntry'], data: { log: selectedLogId }, preserveUrl: true });
    }, [open, selectedLogId, runKey]);

    return selectedLogId === null ? undefined : (cacheRef.current.get(selectedLogId) ?? (entry?.id === selectedLogId ? entry : undefined));
}
