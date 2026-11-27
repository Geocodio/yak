export type AnsiSegment = { text: string; className: string | null };

/** Foreground colors by SGR code, as theme classes; bright codes share their base color. */
const FOREGROUND: Record<number, string> = {
    30: 'text-body',
    31: 'text-fail',
    32: 'text-ok',
    33: 'text-warn',
    34: 'text-info',
    35: 'text-accent-text',
    36: 'text-info',
    37: 'text-body',
    90: 'text-faint',
    91: 'text-fail',
    92: 'text-ok',
    93: 'text-warn',
    94: 'text-info',
    95: 'text-accent-text',
    96: 'text-info',
    97: 'text-body',
};

/** SGR color sequences, plus every other CSI, OSC or two-byte escape, which is dropped. */
const ESCAPE = /\x1b\[([0-9;:]*)m|\x1b\[[0-?]*[ -/]*[@-~]|\x1b\][^\x07\x1b]*(?:\x07|\x1b\\)|\x1b[@-_]/g;

type Style = { color: string | null; isBold: boolean; isDim: boolean };

function applySgr(style: Style, parameters: string): Style {
    const codes = parameters === '' ? [0] : parameters.split(/[;:]/).map(Number);
    const next = { ...style };

    for (let index = 0; index < codes.length; index++) {
        const code = codes[index];

        if (code === 0) {
            next.color = null;
            next.isBold = false;
            next.isDim = false;
        } else if (code === 1) {
            next.isBold = true;
        } else if (code === 2) {
            next.isDim = true;
        } else if (code === 22) {
            next.isBold = false;
            next.isDim = false;
        } else if (code === 39) {
            next.color = null;
        } else if (code in FOREGROUND) {
            next.color = FOREGROUND[code];
        } else if (code === 38 || code === 48) {
            index += codes[index + 1] === 5 ? 2 : codes[index + 1] === 2 ? 4 : 0;
        }
    }

    return next;
}

function classNameOf(style: Style): string | null {
    const color = style.color ?? (style.isDim ? 'text-faint' : null);

    return [color, style.isBold ? 'font-semibold' : null].filter(Boolean).join(' ') || null;
}

/**
 * Splits terminal output into lines of styled segments. Foreground colors and
 * bold carry across line breaks the way a terminal keeps them; background
 * colors, 256-color and truecolor codes, and cursor or title escapes are
 * dropped and their text kept.
 */
export function parseAnsi(text: string): AnsiSegment[][] {
    const lines: AnsiSegment[][] = [[]];
    let style: Style = { color: null, isBold: false, isDim: false };
    let cursor = 0;

    const push = (chunk: string) => {
        chunk.split('\n').forEach((part, index) => {
            if (index > 0) {
                lines.push([]);
            }

            if (part !== '') {
                lines[lines.length - 1].push({ text: part, className: classNameOf(style) });
            }
        });
    };

    for (const match of text.matchAll(ESCAPE)) {
        push(text.slice(cursor, match.index));
        cursor = match.index + match[0].length;

        if (match[1] !== undefined) {
            style = applySgr(style, match[1]);
        }
    }

    push(text.slice(cursor));

    return lines;
}
