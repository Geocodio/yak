import { micromark } from 'micromark';
import { gfm, gfmHtml } from 'micromark-extension-gfm';

type HtmlExtension = NonNullable<NonNullable<Parameters<typeof micromark>[1]>['htmlExtensions']>[number];

/**
 * Raw HTML is dropped, as the server's `html_input: strip` does, instead of
 * being escaped into visible text. The `html*Data` tokens carry the markup.
 */
const stripRawHtml: HtmlExtension = {
    exit: {
        htmlFlowData() {},
        htmlTextData() {},
    },
};

/**
 * Renders Markdown to HTML the way the server does (league/commonmark GFM,
 * raw HTML stripped, unsafe link protocols removed). No settling.
 */
export function renderMarkdown(source: string): string {
    const trimmed = source.trim();

    if (trimmed === '') {
        return '';
    }

    return micromark(trimmed, {
        extensions: [gfm()],
        htmlExtensions: [gfmHtml(), stripRawHtml],
    })
        .replace(/<a href="">/g, '<a>')
        .replace(/<input type="checkbox" disabled="" (checked="" )?\/>/g, (_match, checked) =>
        checked ? '<input checked="" disabled="" type="checkbox">' : '<input disabled="" type="checkbox">',
    );
}

/**
 * Removes only whitespace between block tags, which cannot show on screen.
 */
export function normalizeHtml(html: string): string {
    return html.replace(/>\s+</g, '><').trim();
}

const clarificationBlock = /```clarification\s*\n.+?\n```/gs;

/**
 * The reply text without its clarification block, as the server shows it once
 * the reply is final.
 */
export function stripClarificationBlock(source: string): string {
    return source.replace(clarificationBlock, '').trim();
}

/**
 * Gives a link whose url is still arriving a placeholder target, so the text
 * already reads as a link.
 */
function closeLinkTarget(line: string): string {
    const targetStart = line.lastIndexOf('](');

    if (targetStart === -1) {
        return line;
    }

    let depth = 1;

    for (const character of line.slice(targetStart + 2)) {
        depth += character === '(' ? 1 : character === ')' ? -1 : 0;

        if (depth === 0) {
            return line;
        }
    }

    return `${line.slice(0, targetStart)}](#)`;
}

/**
 * Closes or hides the half-written inline syntax on the last line so it never
 * shows markers that disappear once the rest arrives.
 */
function closeInline(line: string): string {
    line = closeLinkTarget(line)
        .replace(/<\/?([a-zA-Z!][^>]*)?$/, '')
        .replace(/&#?\w*$/, '')
        .replace(/(^|[^\\])\\$/, '$1')
        .replace(/\[([^\]]*)\]\[[^\]]*$/, '[$1]')
        .replace(/(!?\[[^\]]*\])+$/, '')
        .replace(/!\[[^\]]*$/, '')
        .replace(/\[([^\]]*)$/, '$1')
        .replace(/(^|\s)!$/, '$1');

    const tickCount = (line.match(/`/g) ?? []).length;

    if (tickCount % 2 === 1) {
        return line.endsWith('`') ? line.slice(0, -1) : `${line}\``;
    }

    return ['~', '~~', '*', '**'].reduce(closeEmphasis, line);
}

/**
 * Closes one unbalanced emphasis marker at the end of the line, or removes it
 * when the marker itself is what arrived last.
 */
function closeEmphasis(line: string, marker: string): string {
    const outsideCode = line.replace(/`[^`]*`/g, '');
    const withoutDoubles = marker.length === 1 ? outsideCode.replaceAll(marker + marker, '') : outsideCode;
    const markerCount = withoutDoubles.split(marker).length - 1;

    if (markerCount % 2 === 0) {
        return line;
    }

    if (line.trimEnd().endsWith(marker)) {
        return line.trimEnd().slice(0, -marker.length);
    }

    return `${line.trimEnd()}${marker}`;
}

/**
 * Turns the text received so far into Markdown whose rendering only ever grows:
 * hides a clarification block, an unfinished fence opener, a bare list or
 * heading marker and a table until its delimiter row, closes an open fence,
 * and closes or hides unfinished inline syntax.
 */
export function settleMarkdown(source: string): string {
    let text = source.replace(clarificationBlock, '');
    const openClarification = text.search(/^[ \t]*```clarification/m);

    if (openClarification !== -1) {
        text = text.slice(0, openClarification);
    }

    const lines = text.split('\n');
    const completeLines = lines.slice(0, -1);
    let lastLine = lines[lines.length - 1];
    let isInFence = false;

    for (const line of completeLines) {
        if (/^\s*```/.test(line)) {
            isInFence = !isInFence;
        }
    }

    if (isInFence) {
        if (/^\s*`{1,3}\s*$/.test(lastLine)) {
            lastLine = '';
        }
    } else if (
        /^\s*`{1,3}[\w-]*$/.test(lastLine) ||
        /^\s*(#{1,6}|[-*+]|\d+[.)]?|>|-+|=+)?\s*$/.test(lastLine) ||
        /^\s*\|/.test(lastLine) ||
        /^\s*([-*+]|\d+[.)])\s+\[[ xX]?\]?\s*$/.test(lastLine)
    ) {
        lastLine = '';
    } else {
        lastLine = closeInline(lastLine);
    }

    if (!isInFence && lastLine === '' && completeLines.length > 0) {
        completeLines[completeLines.length - 1] = completeLines[completeLines.length - 1].replace(/(^|[^\\])\\$/, '$1');
    }

    if (!isInFence) {
        let tableStart = completeLines.length;

        while (tableStart > 0 && /^\s*\|/.test(completeLines[tableStart - 1])) {
            tableStart--;
        }

        const tableLines = completeLines.slice(tableStart);

        if (tableLines.length > 0 && !tableLines.some((line) => /^\s*\|?\s*:?-{3,}/.test(line))) {
            completeLines.length = tableStart;
        }
    }

    let settled = completeLines.join('\n') + (completeLines.length > 0 ? '\n' : '') + lastLine;

    if (isInFence) {
        settled += (settled.endsWith('\n') ? '' : '\n') + '```';
    }

    return settled;
}

/**
 * Renders a reply that is still arriving: settles the half-written Markdown,
 * then renders it like the final reply.
 */
export function renderStreamingMarkdown(source: string): string {
    return renderMarkdown(settleMarkdown(source));
}
