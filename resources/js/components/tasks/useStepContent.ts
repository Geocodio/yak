import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type { StepContent, } from '@/components/activity/types';
import type { StepDetailSource } from '@/components/activity/StepDetailContext';
import { fetchJsonWithRetry } from '@/lib/fetchJsonWithRetry';
import { logEntries } from '@/routes/tasks';
import type { TranscriptEntry } from '@/types/tasks';

const BATCH_DELAY_MS = 40;
const CACHE_LIMIT = 200;

function toContent(entry: TranscriptEntry): StepContent {
    return entry.kind === 'assistant' ? { thought: entry.fullText ?? entry.text } : { input: entry.input ?? null, output: entry.output ?? null };
}

/**
 * Fetches the command and output behind opened step rows, a few at a time,
 * and keeps the last CACHE_LIMIT of them. A tool call still running has no
 * output yet, so it is not kept: the row asks again once it finishes. A
 * run or attempt switch (`runKey`) drops everything, since the ids belong to
 * another log.
 */
export function useStepContent({ taskId, runKey, openInFullLog }: { taskId: string; runKey: string; openInFullLog?: (logId: number) => void }): StepDetailSource {
    const [cache, setCache] = useState<Map<number, StepContent>>(() => new Map());
    const pendingRef = useRef(new Set<number>());
    const timerRef = useRef<number | null>(null);
    const runKeyRef = useRef(runKey);

    useEffect(() => {
        if (runKeyRef.current !== runKey) {
            runKeyRef.current = runKey;
            pendingRef.current.clear();
            setCache(new Map());
        }
    }, [runKey]);

    useEffect(
        () => () => {
            if (timerRef.current !== null) {
                window.clearTimeout(timerRef.current);
            }
        },
        [],
    );

    const flush = useCallback(() => {
        timerRef.current = null;
        const ids = [...pendingRef.current].slice(0, 20);
        pendingRef.current.clear();
        if (ids.length === 0) {
            return;
        }
        const url = logEntries.url(taskId, { query: { ids } });
        fetchJsonWithRetry<{ entries: TranscriptEntry[] }>(url)
            .then(({ entries }) => {
                setCache((current) => {
                    const next = new Map(current);
                    entries.forEach((entry) => {
                        if (!(entry.kind === 'tool' && entry.output === null)) {
                            next.set(entry.id, toContent(entry));
                        }
                    });
                    while (next.size > CACHE_LIMIT) {
                        next.delete(next.keys().next().value as number);
                    }
                    return next;
                });
            })
            .catch(() => undefined);
    }, [taskId]);

    const request = useCallback(
        (logId: number) => {
            pendingRef.current.add(logId);
            if (timerRef.current === null) {
                timerRef.current = window.setTimeout(flush, BATCH_DELAY_MS);
            }
        },
        [flush],
    );

    return useMemo(() => ({ contentFor: (logId: number) => cache.get(logId), request, openInFullLog }), [cache, request, openInFullLog]);
}
