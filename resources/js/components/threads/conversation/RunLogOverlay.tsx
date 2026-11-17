import { useEffect, useRef, useState } from 'react';
import { LogEntrySheet } from '@/components/shared/LogEntrySheet';
import { TranscriptOverlay } from '@/components/shared/TranscriptOverlay';
import { useMediaQuery } from '@/lib/useMediaQuery';
import { log as runLog } from '@/routes/threads/runs';
import { log as taskLog } from '@/routes/threads/tasks';
import { replaceTaskQuery } from '@/lib/taskQuery';
import type { TranscriptEntry } from '@/types/tasks';
import { useRunLog } from './useRunLog';

/** One run of a thread (or, for kind 'url', the log route in `id`), or one task of a thread made from an old task, whose steps the overlay shows. */
export type RunLogTarget = { kind: 'run' | 'task' | 'url'; id: string; entryId: number | null; headline: string };

const EMPTY_TEXT = 'This run has no recorded steps.';

/**
 * The existing transcript overlay (desktop) or log entry sheet (phone), fed from the run log route. The run and
 * attempt pickers stay out of it: it shows exactly one run. The `?log=` link follows the open entry.
 */
export function RunLogOverlay({ threadId = '', target, onClose }: { threadId?: string; target: RunLogTarget | null; onClose: () => void }) {
    const isDesktop = useMediaQuery('(min-width: 768px)');
    const url = target === null ? null : target.kind === 'url' ? target.id : target.kind === 'run' ? runLog.url({ thread: threadId, run: target.id }) : taskLog.url({ thread: threadId, task: target.id });
    const { entries, status, hasMore, isLoadingMore, moreFailed, loadMore, retry, search } = useRunLog(url);
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

    if (target === null) {
        return null;
    }

    const select = (entryId: number) => {
        setSelectedId(entryId);
        replaceTaskQuery({ log: entryId });
    };
    const close = (open: boolean) => {
        if (!open) {
            replaceTaskQuery({ log: undefined });
            onClose();
        }
    };
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
            headline={target.headline}
            runs={[]}
            currentRunId={targetKey}
            attempts={[]}
            currentAttempt={1}
            selectedLogId={selectedId}
            onSelectLog={select}
            paging={{ ...paging, onSearch: search }}
        />
    ) : (
        <LogEntrySheet open onOpenChange={close} rows={entries} selectedLogId={selectedId} entry={entry} onSelectLog={select} runKey={targetKey} paging={paging} />
    );
}
