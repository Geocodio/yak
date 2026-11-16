import { cn } from '@geocodio/console-ui';
import { ChevronsUpDown } from 'lucide-react';
import { diffWordsWithSpace, parsePatch } from 'diff';
import hljs from 'highlight.js/lib/core';
import css from 'highlight.js/lib/languages/css';
import javascript from 'highlight.js/lib/languages/javascript';
import json from 'highlight.js/lib/languages/json';
import markdown from 'highlight.js/lib/languages/markdown';
import php from 'highlight.js/lib/languages/php';
import typescript from 'highlight.js/lib/languages/typescript';
import xml from 'highlight.js/lib/languages/xml';
import yaml from 'highlight.js/lib/languages/yaml';
import { Fragment, useEffect, useMemo, useState } from 'react';
import type { ThreadDiffFile, ThreadDiffFilePatch } from '@/types/thread-changes';
import { useJson } from '../useJson';
import { DiffSkeleton } from './DiffSkeleton';
import { patchUrl } from './useThreadDiff';

const LANGUAGE_BY_EXTENSION: Record<string, string> = {
    php: 'php',
    ts: 'typescript',
    tsx: 'typescript',
    js: 'javascript',
    json: 'json',
    md: 'markdown',
    css: 'css',
    html: 'xml',
    yml: 'yaml',
};

hljs.registerLanguage('php', php);
hljs.registerLanguage('typescript', typescript);
hljs.registerLanguage('javascript', javascript);
hljs.registerLanguage('json', json);
hljs.registerLanguage('markdown', markdown);
hljs.registerLanguage('css', css);
hljs.registerLanguage('xml', xml);
hljs.registerLanguage('yaml', yaml);

type Segment = { text: string; isChanged: boolean };
type Line = { kind: 'context' | 'added' | 'removed'; oldNumber: number | null; newNumber: number | null; text: string; segments: Segment[] | null };
/** Unchanged lines between hunks. `newTo` is null for the tail, whose length is only known once the file is loaded. */
type Gap = { kind: 'gap'; id: string; newFrom: number; newTo: number | null; delta: number };
type Block = { kind: 'lines'; lines: Line[] } | Gap;

function parseBlocks(patch: string): Block[] {
    const blocks: Block[] = [];
    let previousOldEnd = 1;
    let previousNewEnd = 1;

    for (const hunk of parsePatch(patch).flatMap((parsed) => parsed.hunks)) {
        // A zero-length range names the line before it, so the first line it covers is one further on.
        const oldStart = hunk.oldLines === 0 ? hunk.oldStart + 1 : hunk.oldStart;
        const newStart = hunk.newLines === 0 ? hunk.newStart + 1 : hunk.newStart;
        if (newStart > previousNewEnd) {
            blocks.push({ kind: 'gap', id: `gap-${blocks.length}`, newFrom: previousNewEnd, newTo: newStart - 1, delta: previousOldEnd - previousNewEnd });
        }

        const lines: Line[] = [];
        let oldNumber = hunk.oldStart;
        let newNumber = hunk.newStart;

        for (const raw of hunk.lines) {
            const marker = raw[0];
            const text = raw.slice(1);
            if (marker === '-') {
                lines.push({ kind: 'removed', oldNumber: oldNumber++, newNumber: null, text, segments: null });
            } else if (marker === '+') {
                lines.push({ kind: 'added', oldNumber: null, newNumber: newNumber++, text, segments: null });
            } else if (marker === ' ') {
                lines.push({ kind: 'context', oldNumber: oldNumber++, newNumber: newNumber++, text, segments: null });
            }
        }

        pairWordChanges(lines);
        blocks.push({ kind: 'lines', lines });
        previousOldEnd = oldStart + hunk.oldLines;
        previousNewEnd = newStart + hunk.newLines;
    }

    if (blocks.length > 0) {
        blocks.push({ kind: 'gap', id: 'gap-tail', newFrom: previousNewEnd, newTo: null, delta: previousOldEnd - previousNewEnd });
    }

    return blocks;
}

/** Pairs each run of removed lines with the added run that follows and marks the words that differ. */
function pairWordChanges(lines: Line[]): void {
    let index = 0;
    while (index < lines.length) {
        if (lines[index].kind !== 'removed') {
            index++;
            continue;
        }
        const removedStart = index;
        while (index < lines.length && lines[index].kind === 'removed') {
            index++;
        }
        const addedStart = index;
        while (index < lines.length && lines[index].kind === 'added') {
            index++;
        }
        const pairs = Math.min(addedStart - removedStart, index - addedStart);
        for (let offset = 0; offset < pairs; offset++) {
            const removed = lines[removedStart + offset];
            const added = lines[addedStart + offset];
            const parts = diffWordsWithSpace(removed.text, added.text);
            removed.segments = parts.filter((part) => !part.added).map((part) => ({ text: part.value, isChanged: part.removed === true }));
            added.segments = parts.filter((part) => !part.removed).map((part) => ({ text: part.value, isChanged: part.added === true }));
        }
    }
}

const ROW_TINT: Record<Line['kind'], string> = { context: '', added: 'bg-ok/10', removed: 'bg-fail/10' };
const NUMBER_TINT: Record<Line['kind'], string> = { context: 'text-muted/80', added: 'bg-ok/20 text-ok', removed: 'bg-fail/20 text-fail' };
const SIGN_TINT: Record<Line['kind'], string> = { context: '', added: 'text-ok', removed: 'text-fail' };
const WORD_TINT: Record<'added' | 'removed', string> = { added: 'bg-ok/25', removed: 'bg-fail/25' };
const MARKER: Record<Line['kind'], string> = { context: ' ', added: '+', removed: '−' };

function Code({ line, language }: { line: Line; language: string | null }) {
    if (line.segments !== null && line.kind !== 'context') {
        return (
            <>
                {line.segments.map((segment, index) => (
                    <span key={index} className={cn('rounded-[3px]', segment.isChanged && WORD_TINT[line.kind as 'added' | 'removed'])}>
                        {segment.text}
                    </span>
                ))}
            </>
        );
    }
    if (language !== null) {
        return <span dangerouslySetInnerHTML={{ __html: hljs.highlight(line.text, { language, ignoreIllegals: true }).value }} />;
    }
    return <>{line.text}</>;
}

const NUMBER_CLASS = 'select-none px-1.5 text-right tabular-nums';

function Cell({ line, side, language }: { line: Line | null; side: 'old' | 'new'; language: string | null }) {
    return (
        <div className={cn('grid min-w-full w-max grid-cols-[40px_18px_1fr]', line === null ? 'bg-panel-2/60' : ROW_TINT[line.kind])}>
            <span className={cn(NUMBER_CLASS, line !== null && NUMBER_TINT[line.kind])}>{(side === 'old' ? line?.oldNumber : line?.newNumber) ?? ''}</span>
            <span className={cn('select-none text-center', line !== null && SIGN_TINT[line.kind])}>{line === null ? '' : MARKER[line.kind]}</span>
            <span className="whitespace-pre px-1.5">{line !== null && <Code line={line} language={language} />}</span>
        </div>
    );
}

function Unified({ lines, language }: { lines: Line[]; language: string | null }) {
    return (
        <div className="overflow-x-auto">
            {lines.map((line, index) => (
                <div key={index} className={cn('grid min-w-full w-max grid-cols-[40px_40px_18px_1fr]', ROW_TINT[line.kind])}>
                    <span className={cn(NUMBER_CLASS, NUMBER_TINT[line.kind])}>{line.oldNumber ?? ''}</span>
                    <span className={cn(NUMBER_CLASS, NUMBER_TINT[line.kind])}>{line.newNumber ?? ''}</span>
                    <span className={cn('select-none text-center', SIGN_TINT[line.kind])}>{MARKER[line.kind]}</span>
                    <span className="whitespace-pre px-1.5">
                        <Code line={line} language={language} />
                    </span>
                </div>
            ))}
        </div>
    );
}

/** Left and right cells per row: context on both sides, removed runs beside the added run that follows. */
function splitRows(lines: Line[]): [Line | null, Line | null][] {
    const rows: [Line | null, Line | null][] = [];
    let index = 0;
    while (index < lines.length) {
        if (lines[index].kind === 'context') {
            rows.push([lines[index], lines[index]]);
            index++;
            continue;
        }
        const removed: Line[] = [];
        const added: Line[] = [];
        while (index < lines.length && lines[index].kind === 'removed') {
            removed.push(lines[index++]);
        }
        while (index < lines.length && lines[index].kind === 'added') {
            added.push(lines[index++]);
        }
        for (let row = 0; row < Math.max(removed.length, added.length); row++) {
            rows.push([removed[row] ?? null, added[row] ?? null]);
        }
    }
    return rows;
}

function Split({ lines, language }: { lines: Line[]; language: string | null }) {
    const rows = splitRows(lines);

    return (
        <div className="grid grid-cols-2 divide-x divide-hair" data-testid="changes-diff-split">
            <div className="overflow-x-auto">
                {rows.map(([left], index) => (
                    <Cell key={index} line={left} side="old" language={language} />
                ))}
            </div>
            <div className="overflow-x-auto">
                {rows.map(([, right], index) => (
                    <Cell key={index} line={right} side="new" language={language} />
                ))}
            </div>
        </div>
    );
}

function contextLines(content: string[], gap: Gap): Line[] {
    const to = gap.newTo ?? content.length;
    const lines: Line[] = [];
    for (let number = gap.newFrom; number <= to && number <= content.length; number++) {
        lines.push({ kind: 'context', oldNumber: number + gap.delta, newNumber: number, text: content[number - 1], segments: null });
    }
    return lines;
}

function GapRow({ gap, count, isLoading, error, onExpand }: { gap: Gap; count: number | null; isLoading: boolean; error: string | null; onExpand: () => void }) {
    const noun = count === null ? 'the remaining lines' : `${count} hidden ${count === 1 ? 'line' : 'lines'}`;

    return (
        <button
            type="button"
            onClick={onExpand}
            disabled={isLoading}
            className="flex w-full cursor-pointer items-center gap-2 border-y border-hair bg-panel-2 px-2.5 py-[3px] text-left font-sans text-[12px] text-muted first:border-t-0 last:border-b-0 hover:text-accent-text disabled:cursor-default"
            data-testid="changes-diff-gap"
            data-gap={gap.id}
        >
            <ChevronsUpDown size={12} aria-hidden />
            {error ?? (isLoading ? 'Loading lines' : `Show ${noun}`)}
        </button>
    );
}

type Props = {
    threadId: string;
    file: ThreadDiffFile;
    mode: 'unified' | 'split';
    /** Whether hidden lines can be read from the sandbox's copy of the file. */
    canExpand?: boolean;
};

export function DiffViewer({ threadId, file, mode, canExpand = false }: Props) {
    const { data, isLoading, error } = useJson<ThreadDiffFilePatch>(patchUrl(threadId, file.id));
    const patch = data?.patch ?? null;
    const blocks = useMemo(() => {
        try {
            return patch === null ? [] : parseBlocks(patch);
        } catch {
            return null;
        }
    }, [patch]);
    const extension = file.path.split('.').pop()?.toLowerCase() ?? '';
    const language = LANGUAGE_BY_EXTENSION[extension] ?? null;
    const [expandedGaps, setExpandedGaps] = useState<Set<string>>(new Set());
    const [content, setContent] = useState<string[] | null>(null);
    const [isLoadingContent, setIsLoadingContent] = useState(false);
    const [contentError, setContentError] = useState<string | null>(null);

    useEffect(() => {
        if (expandedGaps.size === 0 || content !== null || isLoadingContent) {
            return;
        }
        setIsLoadingContent(true);
        fetch(`/threads/${threadId}/files/content?${new URLSearchParams({ scope: 'repo', path: file.path })}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(async (response) => {
                const body = (await response.json().catch(() => ({}))) as { content?: string | null };
                if (!response.ok || typeof body.content !== 'string') {
                    throw new Error('The hidden lines could not be loaded. The sandbox may be asleep.');
                }
                const lines = body.content.split('\n');
                setContent(lines.length > 0 && lines[lines.length - 1] === '' ? lines.slice(0, -1) : lines);
            })
            .catch((failure: unknown) => {
                setContentError(failure instanceof Error ? failure.message : 'The hidden lines could not be loaded.');
                setExpandedGaps(new Set());
            })
            .finally(() => setIsLoadingContent(false));
    }, [expandedGaps, content, isLoadingContent, threadId, file.path]);

    let body;
    if (file.isBinary) {
        body = <p className="p-3 font-sans text-[12.5px] text-muted">Binary file</p>;
    } else if (file.isTooLarge) {
        body = <p className="p-3 font-sans text-[12.5px] text-muted">Too large to show</p>;
    } else if (data === null) {
        body = isLoading ? <DiffSkeleton additions={file.additions} deletions={file.deletions} /> : <p className="p-3 font-sans text-[12.5px] text-muted">{error ?? 'The diff could not be loaded.'}</p>;
    } else if (blocks === null) {
        body = <p className="p-3 font-sans text-[12.5px] text-muted">The diff could not be read.</p>;
    } else if (patch === null || blocks.length === 0) {
        body = <p className="p-3 font-sans text-[12.5px] text-muted">No content changes</p>;
    } else {
        const render = (lines: Line[]) => (mode === 'split' ? <Split lines={lines} language={language} /> : <Unified lines={lines} language={language} />);
        const canExpandFile = canExpand && file.status !== 'deleted' && file.status !== 'added';

        body = blocks.map((block, index) => {
            if (block.kind === 'lines') {
                return <Fragment key={index}>{render(block.lines)}</Fragment>;
            }
            if (!canExpandFile) {
                return block.newTo === null ? null : (
                    <div key={index} className="border-y border-hair bg-panel-2 px-3 py-1 text-center font-sans text-[11.5px] text-muted first:border-t-0" data-testid="changes-diff-gap-static">
                        {block.newTo - block.newFrom + 1} unchanged {block.newTo - block.newFrom === 0 ? 'line' : 'lines'}
                    </div>
                );
            }
            if (expandedGaps.has(block.id) && content !== null) {
                const lines = contextLines(content, block);
                return lines.length === 0 ? null : <Fragment key={index}>{render(lines)}</Fragment>;
            }
            const count = block.newTo === null ? (content === null ? null : Math.max(0, content.length - block.newFrom + 1)) : block.newTo - block.newFrom + 1;
            if (count === 0) {
                return null;
            }

            return <GapRow key={index} gap={block} count={count} isLoading={expandedGaps.has(block.id) && isLoadingContent} error={contentError} onExpand={() => { setContentError(null); setExpandedGaps((current) => new Set(current).add(block.id)); }} />;
        });
    }

    return (
        <div className="font-mono text-[12px] leading-5 text-body" data-testid="changes-diff-viewer">
            {body}
        </div>
    );
}
