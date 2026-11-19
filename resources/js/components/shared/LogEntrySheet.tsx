import { Button, IconButton, Sheet, Skeleton, cn } from '@geocodio/console-ui';
import { ChevronLeft } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import { Prose } from '@/components/Prose';
import { useTranscriptEntry } from '@/components/shared/useTranscriptEntry';
import type { TranscriptEntry } from '@/types/tasks';
import type { TranscriptPaging, TranscriptRow } from '@/components/shared/TranscriptOverlay';

function Block({ title, children, error }: { title: string; children?: ReactNode; error?: boolean }) {
    return (
        <div className="mt-4">
            <div className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-faint">{title}</div>
            {children === null || children === undefined || children === '' ? (
                <p className="text-[12px] text-faint">No {title.toLowerCase()} recorded.</p>
            ) : (
                <pre
                    className={cn(
                        'whitespace-pre-wrap break-all rounded-card border border-hair bg-panel-2 p-3 font-mono text-[11.5px] leading-relaxed',
                        error && 'border-fail/30 bg-fail-soft/40 text-fail',
                    )}
                >
                    {children}
                </pre>
            )}
        </div>
    );
}

/** The Output block, with output over 40 lines collapsed behind a "Show all N lines" button. */
function OutputBlock({ output, error }: { output: string | null | undefined; error?: boolean }) {
    const [expanded, setExpanded] = useState(false);
    const lines = (output ?? '').split('\n');
    const isLong = lines.length > 40;
    const shown = expanded || !isLong ? output : lines.slice(0, 40).join('\n');

    return (
        <>
            <Block title="Output" error={error}>
                {shown}
            </Block>
            {isLong && !expanded && (
                <Button variant="tertiary" className="mt-2 w-full" onClick={() => setExpanded(true)}>
                    Show all {lines.length} lines
                </Button>
            )}
        </>
    );
}

export function LogEntrySheet({
    open,
    onOpenChange,
    rows,
    selectedLogId,
    entry,
    onSelectLog,
    runKey,
    paging,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    rows: TranscriptRow[];
    selectedLogId: number | null;
    entry: TranscriptEntry | null | undefined;
    onSelectLog: (logId: number) => void;
    runKey: string;
    paging?: Omit<TranscriptPaging, 'onSearch' | 'query'>;
}) {
    const idx = rows.findIndex((row) => row.id === selectedLogId);
    const sel = idx >= 0 ? idx : 0;

    // A cold open (the "open full transcript" button, no `?log=`) has
    // nothing selected yet: pick the first row once so a fetch has
    // something to ask for.
    useEffect(() => {
        if (open && rows.length > 0 && selectedLogId === null) {
            onSelectLog(rows[0].id);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, rows, selectedLogId]);

    // Next at the end of the loaded steps asks for the next page and moves to its first step once it arrives.
    const [advanceFrom, setAdvanceFrom] = useState<number | null>(null);
    useEffect(() => {
        if (advanceFrom !== null && rows.length > advanceFrom) {
            onSelectLog(rows[advanceFrom].id);
            setAdvanceFrom(null);
        } else if (advanceFrom !== null && paging?.moreFailed) {
            setAdvanceFrom(null);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [advanceFrom, rows.length, paging?.moreFailed]);

    const current = useTranscriptEntry({ open, selectedLogId, entry, runKey, fetchesEntry: paging === undefined });

    const step = (offset: number) => {
        if (rows.length === 0) {
            return;
        }
        const next = Math.min(rows.length - 1, Math.max(0, sel + offset));
        onSelectLog(rows[next].id);
    };

    return (
        <Sheet
            open={open}
            onOpenChange={onOpenChange}
            title="Log entry"
            hideTitle
            side="bottom"
            className="h-dvh max-h-dvh rounded-none px-0 pt-[env(safe-area-inset-top)]"
            data-testid="log-entry-sheet"
        >
            <div className="flex h-full flex-col">
                <div className="flex h-12 shrink-0 items-center gap-2 border-b border-hair px-3">
                    <IconButton label="Back to activity" onClick={() => onOpenChange(false)}>
                        <ChevronLeft size={16} />
                    </IconButton>
                    <span className="text-[13px] font-semibold">Activity</span>
                    <span className="ml-auto text-[11px] text-faint">
                        step {sel + 1} of {rows.length}
                        {paging?.hasMore ? '+' : ''}
                    </span>
                </div>
                <div className="min-h-0 flex-1 overflow-auto p-4">
                    {current ? (
                        <>
                            <div className="flex flex-wrap items-center gap-2 text-[11px] text-faint">
                                {current.badge && <span className="rounded-chip bg-panel-2 px-1 font-mono text-[10px] text-muted">{current.badge}</span>}
                                <span>{current.at}</span>
                                {current.tool && (
                                    <>
                                        <span>·</span>
                                        <span>
                                            tool · <span className="font-mono">{current.tool}</span>
                                        </span>
                                    </>
                                )}
                                {current.error && (
                                    <>
                                        <span>·</span>
                                        <span className="text-fail">errored</span>
                                    </>
                                )}
                            </div>
                            <h3 className={cn('mt-1 line-clamp-2 text-[14px] font-semibold', current.error && 'text-fail')}>{current.text}</h3>
                            {current.kind === 'prompt' && current.prompt ? (
                                <>
                                    <dl className="mt-4 grid grid-cols-2 gap-3 text-[11px]">
                                        {Object.entries(current.prompt.meta).map(([k, v]) => (
                                            <div key={k} className="rounded-card border border-hair bg-panel px-3 py-2">
                                                <dt className="font-mono text-faint">{k}</dt>
                                                <dd className="mt-0.5 font-mono">{v}</dd>
                                            </div>
                                        ))}
                                    </dl>
                                    <Block title="User prompt">{current.prompt.user}</Block>
                                    <Block title="System prompt">{current.prompt.system}</Block>
                                </>
                            ) : current.kind === 'assistant' ? (
                                <div className="mt-4 rounded-card border border-hair bg-panel p-4">
                                    <Prose html={current.html ?? ''} />
                                </div>
                            ) : current.kind === 'level' ? (
                                current.details && current.details.length > 0 ? (
                                    current.details.map((detail) => (
                                        <Block key={detail.label} title={detail.label} error={detail.error}>
                                            {detail.value}
                                        </Block>
                                    ))
                                ) : (
                                    <p className="mt-4 text-[12px] text-faint">No details recorded for this entry.</p>
                                )
                            ) : (
                                <>
                                    <Block title={current.tool === 'Bash' ? 'Command' : 'Input'}>{current.input}</Block>
                                    <OutputBlock key={current.id} output={current.output} error={current.error} />
                                </>
                            )}
                        </>
                    ) : paging?.status === 'error' ? (
                        <div className="flex flex-col items-start gap-2 text-[12px] text-fail" data-testid="transcript-error">
                            The log could not be loaded.
                            <Button variant="secondary" onClick={paging.onRetry} data-testid="transcript-retry">
                                Retry
                            </Button>
                        </div>
                    ) : paging?.status === 'ready' && rows.length === 0 ? (
                        <p className="text-[12px] text-faint" data-testid="transcript-empty">
                            {paging.emptyText}
                        </p>
                    ) : paging?.status === 'loading' ? (
                        <div className="flex flex-col gap-2" data-testid="transcript-loading">
                            {[0, 1, 2, 3].map((line) => (
                                <Skeleton key={line} className="h-4 w-full" />
                            ))}
                        </div>
                    ) : (
                        <p className="text-[12px] text-faint">Loading entry…</p>
                    )}
                </div>
                {paging?.moreFailed && (
                    <div className="flex shrink-0 items-center gap-2 border-t border-hair px-3 py-2 text-[12px] text-fail" data-testid="transcript-more-error">
                        The next steps could not be loaded.
                        <Button variant="secondary" onClick={paging.onLoadMore} data-testid="transcript-more-retry">
                            Retry
                        </Button>
                    </div>
                )}
                <div className="flex shrink-0 items-center gap-2 border-t border-hair px-3 py-2 pb-[calc(0.5rem+env(safe-area-inset-bottom))]">
                    <Button variant="secondary" className="flex-1" onClick={() => step(-1)} disabled={sel <= 0}>
                        Previous
                    </Button>
                    <Button variant="secondary" className="flex-1" onClick={() => {
                            if (sel >= rows.length - 1 && paging?.hasMore) {
                                setAdvanceFrom(rows.length);
                                paging.onLoadMore();
                            } else {
                                step(1);
                            }
                        }} disabled={sel >= rows.length - 1 && !paging?.hasMore}>
                        Next
                    </Button>
                </div>
            </div>
        </Sheet>
    );
}
