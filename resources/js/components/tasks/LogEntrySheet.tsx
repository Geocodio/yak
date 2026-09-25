import { Button, IconButton, Sheet, cn } from '@geocodio/console-ui';
import { ChevronLeft } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import { useTranscriptEntry } from '@/components/tasks/useTranscriptEntry';
import type { ActivityRow, TranscriptEntry } from '@/types/tasks';

function Block({ title, children, error }: { title: string; children?: ReactNode; error?: boolean }) {
    return (
        <div className="mt-4">
            <div className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-faint">{title}</div>
            <pre
                className={cn(
                    'whitespace-pre-wrap break-all rounded-card border border-hair bg-panel-2 p-3 font-mono text-[11.5px] leading-relaxed',
                    error && 'border-fail/30 bg-fail-soft/40 text-fail',
                )}
            >
                {children}
            </pre>
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
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    rows: ActivityRow[];
    selectedLogId: number | null;
    entry: TranscriptEntry | null | undefined;
    onSelectLog: (logId: number) => void;
    runKey: string;
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

    const current = useTranscriptEntry({ open, selectedLogId, entry, runKey });

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
            className="h-dvh max-h-dvh rounded-none p-0"
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
                            <h3 className={cn('mt-1 text-[14px] font-semibold', current.error && 'text-fail')}>{current.text}</h3>
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
                                <div className="mt-4 rounded-card border border-hair bg-panel p-4 text-[13px] leading-relaxed">{current.text}</div>
                            ) : (
                                <>
                                    <Block title={current.tool === 'Bash' ? 'Command' : 'Input'}>{current.input}</Block>
                                    <OutputBlock key={current.id} output={current.output} error={current.error} />
                                </>
                            )}
                        </>
                    ) : (
                        <p className="text-[12px] text-faint">Loading entry…</p>
                    )}
                </div>
                <div className="flex shrink-0 items-center gap-2 border-t border-hair px-3 py-2 pb-[calc(0.5rem+env(safe-area-inset-bottom))]">
                    <Button variant="secondary" className="flex-1" onClick={() => step(-1)} disabled={sel <= 0}>
                        Previous
                    </Button>
                    <Button variant="secondary" className="flex-1" onClick={() => step(1)} disabled={sel >= rows.length - 1}>
                        Next
                    </Button>
                </div>
            </div>
        </Sheet>
    );
}
