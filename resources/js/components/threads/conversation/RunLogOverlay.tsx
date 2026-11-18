import { useEffect, useRef, useState } from 'react';
import { LogEntrySheet } from '@/components/shared/LogEntrySheet';
import { TranscriptOverlay } from '@/components/shared/TranscriptOverlay';
import { useMediaQuery } from '@/lib/useMediaQuery';
import { formatMs, formatWhen } from '@/lib/format';
import { show as showThread } from '@/routes/threads';
import { log as runLog } from '@/routes/threads/runs';
import { log as taskLog } from '@/routes/threads/tasks';
import { replaceTaskQuery } from '@/lib/taskQuery';
import type { TranscriptEntry } from '@/types/tasks';
import { useRunLog } from './useRunLog';

/** One run of a thread (or, for kind 'url', the log route in `id`), or one task of a thread made from an old task, whose steps the overlay shows. */
export type RunLogTarget = { kind: 'run' | 'task' | 'url'; id: string; entryId: number | null; headline: string };

/** A run the overlay can move to, with what the header says about it: "9:52 AM · 38 steps · 14m · Sonnet 5.5". */
export type RunLogSibling = { target: RunLogTarget; meta: string };

/** The header facts of a run, in the order the log header shows them; a missing fact is left out. */
export function runLogMeta(run: { startedAt: string | null; stepCount: number; durationSeconds: number; model: string | null }, modelLabels: Record<string, string>): string {
    return [
        run.startedAt === null ? null : formatWhen(run.startedAt),
        run.stepCount > 0 ? `${run.stepCount} ${run.stepCount === 1 ? 'step' : 'steps'}` : null,
        run.durationSeconds > 0 ? formatMs(run.durationSeconds * 1000) : null,
        run.model === null ? null : (modelLabels[run.model] ?? run.model),
    ]
        .filter((part) => part !== null)
        .join(' · ');
}

const EMPTY_TEXT = 'This run has no recorded steps.';

/**
 * The existing transcript overlay (desktop) or log entry sheet (phone), fed from the run log route. The run and
 * attempt pickers stay out of it: it shows exactly one run. The `?log=` link follows the open entry.
 */
export function RunLogOverlay({
    threadId = '',
    target,
    isLive = false,
    runs = [],
    onSelectRun,
    onClose,
}: {
    threadId?: string;
    target: RunLogTarget | null;
    /** The run has not ended, so entries without output are read again. */
    isLive?: boolean;
    /** The runs of the thread (or pull request) in order, oldest first; Previous run and Next run move along them. */
    runs?: RunLogSibling[];
    onSelectRun?: (target: RunLogTarget) => void;
    onClose: () => void;
}) {
    const isDesktop = useMediaQuery('(min-width: 768px)');
    const url = target === null ? null : target.kind === 'url' ? target.id : target.kind === 'run' ? runLog.url({ thread: threadId, run: target.id }) : taskLog.url({ thread: threadId, task: target.id });
    const { entries, status, hasMore, isLoadingMore, moreFailed, loadMore, retry, search } = useRunLog(url, isLive);
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const lastEntry = useRef<TranscriptEntry | undefined>(undefined);
    const targetKey = target === null ? '' : `${target.kind}:${target.id}`;

    useEffect(() => setSelectedId(target?.entryId ?? null), [targetKey, target?.entryId]);

    // A deep link to an entry beyond the first page loads pages until it is found.
    useEffect(() => {
        if (selectedId !== null && status === 'ready' && hasMore && !isLoadingMore && !moreFailed && !entries.some((entry) => entry.id === selectedId)) {
            loadMore();
        }
    }, [selectedId, status, hasMore, isLoadingMore, moreFailed, entries, loadMore]);

    // A link to something that is not a step of this run (the result line of a step, say) opens on the first step.
    useEffect(() => {
        if (selectedId !== null && status === 'ready' && !hasMore && entries.length > 0 && lastEntry.current?.id !== selectedId && !entries.some((entry) => entry.id === selectedId)) {
            setSelectedId(null);
        }
    }, [selectedId, status, hasMore, entries]);

    if (target === null) {
        return null;
    }

    // Only a thread's log has an address that opens it again; a pull request's or a repository's does not.
    const writeUrl = (patch: { log: number | undefined }) => {
        if (target.kind !== 'url') {
            replaceTaskQuery(patch);
        }
    };
    const select = (entryId: number) => {
        setSelectedId(entryId);
        writeUrl({ log: entryId });
    };
    const close = (open: boolean) => {
        if (!open) {
            writeUrl({ log: undefined });
            onClose();
        }
    };
    const position = runs.findIndex((run) => run.target.kind === target.kind && run.target.id === target.id);
    const canMoveBetweenRuns = onSelectRun !== undefined && runs.length > 1 && position >= 0;
    const goToRun = (offset: number) => {
        const sibling = runs[position + offset];
        if (sibling !== undefined && onSelectRun !== undefined) {
            onSelectRun({ ...sibling.target, entryId: null });
        }
    };
    const runLine = [canMoveBetweenRuns ? `Run ${position + 1} of ${runs.length}` : null, position >= 0 ? runs[position].meta : null].filter((part) => part !== null && part !== '').join(' · ') || target.headline;
    const linkFor = threadId === '' || target.kind === 'url' ? undefined : (entryId: number) => `${window.location.origin}${showThread.url({ thread: threadId }, { query: { log: entryId } })}`;
    const paging = { status, onRetry: retry, emptyText: EMPTY_TEXT, hasMore, isLoadingMore, moreFailed, onLoadMore: loadMore };
    // A search can drop the open step from the list; its detail stays until another step is picked.
    const found = entries.find((candidate) => candidate.id === selectedId);
    lastEntry.current = found ?? (lastEntry.current?.id === selectedId ? lastEntry.current : undefined);
    const entry = lastEntry.current;

    return isDesktop ? (
        <TranscriptOverlay
            open
            onOpenChange={close}
            rows={entries}
            entry={entry}
            runLine={runLine}
            onPreviousRun={canMoveBetweenRuns ? (position > 0 ? () => goToRun(-1) : null) : undefined}
            onNextRun={canMoveBetweenRuns ? (position < runs.length - 1 ? () => goToRun(1) : null) : undefined}
            runKey={targetKey}
            linkFor={linkFor}
            selectedLogId={selectedId}
            onSelectLog={select}
            paging={{ ...paging, onSearch: search }}
        />
    ) : (
        <LogEntrySheet open onOpenChange={close} rows={entries} selectedLogId={selectedId} entry={entry} onSelectLog={select} runKey={targetKey} paging={paging} />
    );
}
