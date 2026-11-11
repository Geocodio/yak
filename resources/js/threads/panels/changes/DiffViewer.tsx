import { Skeleton, cn } from '@geocodio/console-ui';
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
import { Fragment, useMemo } from 'react';
import type { ThreadDiffFile, ThreadDiffFilePatch } from '@/types/thread-changes';
import { useJson } from '../useJson';

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
type Block = { kind: 'lines'; lines: Line[] } | { kind: 'gap'; count: number };

function parseBlocks(patch: string): Block[] {
    const blocks: Block[] = [];
    let previousOldEnd = 1;

    for (const hunk of parsePatch(patch).flatMap((parsed) => parsed.hunks)) {
        const gap = hunk.oldStart - previousOldEnd;
        if (gap > 0) {
            blocks.push({ kind: 'gap', count: gap });
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
        previousOldEnd = hunk.oldStart + hunk.oldLines;
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
const WORD_TINT: Record<'added' | 'removed', string> = { added: 'bg-ok/25', removed: 'bg-fail/25' };
const MARKER: Record<Line['kind'], string> = { context: ' ', added: '+', removed: '-' };

function Code({ line, language }: { line: Line; language: string | null }) {
    if (line.segments !== null && line.kind !== 'context') {
        return (
            <>
                {line.segments.map((segment, index) => (
                    <span key={index} className={segment.isChanged ? WORD_TINT[line.kind as 'added' | 'removed'] : undefined}>
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

function Gap({ count }: { count: number }) {
    return (
        <div className="bg-app px-3 py-1 text-center text-[11.5px] text-muted" data-testid="changes-diff-gap">
            {count} unchanged {count === 1 ? 'line' : 'lines'}
        </div>
    );
}

function Cell({ line, side, language }: { line: Line | null; side: 'old' | 'new'; language: string | null }) {
    return (
        <div className={cn('flex min-w-0', line === null ? 'bg-app' : ROW_TINT[line.kind])}>
            <span className="w-10 shrink-0 select-none px-1 text-right text-muted">{(side === 'old' ? line?.oldNumber : line?.newNumber) ?? ''}</span>
            <span className="w-4 shrink-0 select-none text-center text-muted">{line === null ? '' : MARKER[line.kind]}</span>
            <span className="min-w-0 flex-1 whitespace-pre-wrap break-all pr-2">{line !== null && <Code line={line} language={language} />}</span>
        </div>
    );
}

function Unified({ lines, language }: { lines: Line[]; language: string | null }) {
    return (
        <>
            {lines.map((line, index) => (
                <div key={index} className={cn('flex', ROW_TINT[line.kind])}>
                    <span className="w-10 shrink-0 select-none px-1 text-right text-muted">{line.oldNumber ?? ''}</span>
                    <span className="w-10 shrink-0 select-none px-1 text-right text-muted">{line.newNumber ?? ''}</span>
                    <span className="w-4 shrink-0 select-none text-center text-muted">{MARKER[line.kind]}</span>
                    <span className="min-w-0 flex-1 whitespace-pre-wrap break-all pr-2">
                        <Code line={line} language={language} />
                    </span>
                </div>
            ))}
        </>
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
    return (
        <>
            {splitRows(lines).map(([left, right], index) => (
                <div key={index} className="grid grid-cols-2 divide-x divide-hair">
                    <Cell line={left} side="old" language={language} />
                    <Cell line={right} side="new" language={language} />
                </div>
            ))}
        </>
    );
}

export function DiffViewer({ threadId, file, mode }: { threadId: number; file: ThreadDiffFile; mode: 'unified' | 'split' }) {
    const { data, isLoading, error } = useJson<ThreadDiffFilePatch>(`/threads/${threadId}/diff/files/${file.id}`);
    const patch = data?.patch ?? null;
    const blocks = useMemo(() => (patch === null ? [] : parseBlocks(patch)), [patch]);
    const extension = file.path.split('.').pop()?.toLowerCase() ?? '';
    const language = LANGUAGE_BY_EXTENSION[extension] ?? null;

    let body;
    if (file.isBinary) {
        body = <p className="p-3 text-[12.5px] text-muted">Binary file</p>;
    } else if (file.isTooLarge) {
        body = <p className="p-3 text-[12.5px] text-muted">Too large to show</p>;
    } else if (data === null) {
        body = isLoading ? <Skeleton className="m-3 h-24" /> : <p className="p-3 text-[12.5px] text-muted">{error ?? 'The diff could not be loaded.'}</p>;
    } else if (patch === null || blocks.length === 0) {
        body = <p className="p-3 text-[12.5px] text-muted">No content changes</p>;
    } else {
        body = blocks.map((block, index) => (
            <Fragment key={index}>
                {block.kind === 'gap' ? <Gap count={block.count} /> : mode === 'split' ? <Split lines={block.lines} language={language} /> : <Unified lines={block.lines} language={language} />}
            </Fragment>
        ));
    }

    return (
        <div className="overflow-x-auto rounded-card border border-hair font-mono text-[12px] leading-5 text-body" data-testid="changes-diff-viewer">
            {body}
        </div>
    );
}
