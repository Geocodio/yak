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

    return line;
}

/**
 * Splits text into what is outside code spans and the length of a code span
 * left open at the end (0 when none), and where that opener ends.
 */
function codeSpanState(text: string): { outside: string; openRun: number; openEnd: number } {
    let outside = '';
    let openRun = 0;
    let openEnd = 0;
    let index = 0;

    while (index < text.length) {
        if (text[index] === '`') {
            let end = index;

            while (text[end] === '`') {
                end++;
            }

            if (openRun === 0) {
                openRun = end - index;
                openEnd = end;
            } else if (end - index === openRun) {
                openRun = 0;
            }

            index = end;
            continue;
        }

        if (openRun === 0) {
            outside += text[index];
        }

        index++;
    }

    return { outside, openRun, openEnd };
}

/**
 * Closes a code span of any backtick length left open, or removes its opener
 * when nothing follows it yet.
 */
function closeCodeSpan(text: string): string {
    const { openRun, openEnd } = codeSpanState(text);

    if (openRun === 0) {
        return text;
    }

    if (openEnd === text.length) {
        return text.slice(0, -openRun);
    }

    return `${text.replace(/`+$/, '')}${'`'.repeat(openRun)}`;
}

/**
 * Closes the underscore emphasis still open at the end, counting only
 * delimiters CommonMark can pair: an opener is not preceded by a letter or
 * digit and a closer is not followed by one, so `snake_case` is left alone.
 */
function closeUnderscore(text: string): string {
    text = text.replace(/(^|\s)_+$/, '$1');

    const { outside } = codeSpanState(text);
    let pending = 0;

    for (const run of outside.matchAll(/_+/g)) {
        const before = outside[run.index - 1] ?? ' ';
        const after = outside[run.index + run[0].length] ?? ' ';
        const canOpen = !/\s/.test(after) && !/[\p{L}\p{N}]/u.test(before);
        const canClose = !/\s/.test(before) && !/[\p{L}\p{N}]/u.test(after);

        if (canOpen) {
            pending += run[0].length;
        } else if (canClose) {
            pending = Math.max(0, pending - run[0].length);
        }
    }

    return pending === 0 ? text : `${text.trimEnd()}${'_'.repeat(Math.min(pending, 3))}`;
}

const blockStart = /^\s*([-*+>]|\d+[.)]|#{1,6})\s/;

/**
 * Closes the code span and emphasis left open anywhere in the paragraph that
 * is still being written, after the block marker that starts its first line.
 */
function closeParagraph(paragraph: string): string {
    const marker = paragraph.match(blockStart)?.[0] ?? '';
    let body = closeCodeSpan(paragraph.slice(marker.length));

    body = ['~', '~~', '*', '**'].reduce(closeEmphasis, body);

    return marker + closeUnderscore(body);
}

/**
 * Closes one unbalanced emphasis marker at the end of the text, or removes it
 * when the marker itself is what arrived last.
 */
function closeEmphasis(line: string, marker: string): string {
    const outsideCode = codeSpanState(line).outside;
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

    let paragraphStart = completeLines.length;

    if (!isInFence && !blockStart.test(lastLine)) {
        while (paragraphStart > 0 && completeLines[paragraphStart - 1].trim() !== '' && !/^\s*\|/.test(completeLines[paragraphStart - 1])) {
            paragraphStart--;

            if (blockStart.test(completeLines[paragraphStart])) {
                break;
            }
        }
    }

    const paragraphLines = completeLines.slice(paragraphStart);
    let paragraph = isInFence ? '' : paragraphLines.join('\n');

    if (!isInFence) {
        paragraph = closeParagraph(lastLine === '' ? paragraph : `${paragraph}${paragraph === '' ? '' : '\n'}${lastLine}`);
    }

    const head = completeLines.slice(0, isInFence ? completeLines.length : paragraphStart);
    const tail = isInFence ? lastLine : paragraph + (lastLine === '' && paragraph !== '' ? '\n' : '');
    let settled = head.join('\n') + (head.length > 0 ? '\n' : '') + tail;

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
