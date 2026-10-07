/**
 * `[Image #1]` / `[File #2]` tokens in message text. A token only counts
 * when it names an attachment on the draft (`refs`); anything else that
 * happens to look like one is ordinary, editable text.
 */

export type TokenRange = { start: number; end: number; ref: string };

// Mirrors TaskAttachment::TOKEN_PATTERN.
const TOKEN = /\[((?:Image|File) #\d+)\]/g;

export function tokenText(ref: string): string {
    return `[${ref}]`;
}

export function findTokens(value: string, refs: ReadonlySet<string>): TokenRange[] {
    const ranges: TokenRange[] = [];

    for (const match of value.matchAll(TOKEN)) {
        if (refs.has(match[1])) {
            ranges.push({ start: match.index, end: match.index + match[0].length, ref: match[1] });
        }
    }

    return ranges;
}

/** The token the caret is strictly inside, if any (its edges don't count). */
export function tokenAround(value: string, refs: ReadonlySet<string>, position: number): TokenRange | null {
    return findTokens(value, refs).find((range) => range.start < position && position < range.end) ?? null;
}

/**
 * Text to insert at `start..end` so the tokens sit apart from the words
 * around them: a leading space unless the caret already follows
 * whitespace, and a trailing one so typing carries on naturally.
 */
export function tokensForInsertion(value: string, start: number, end: number, refs: string[]): string {
    const before = value.slice(0, start);
    const after = value.slice(end);
    const lead = before === '' || /\s$/.test(before) ? '' : ' ';
    const trail = /^\s/.test(after) ? '' : ' ';

    return lead + refs.map(tokenText).join(' ') + trail;
}

/** Remove the tokens for `refs`, along with one space that separated each. */
export function stripTokens(value: string, refs: string[]): string {
    return refs.reduce((text, ref) => {
        const escaped = tokenText(ref).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

        return text.replace(new RegExp(`${escaped} ?|(?:^| )${escaped}`, 'g'), '');
    }, value);
}

/**
 * Keep tokens whole across an arbitrary edit. Diffs `previous` against
 * `next` to find the region that changed; an edit that clipped a token
 * (word-delete, cut, typing over a selection) takes the whole token with
 * it, and an insertion that landed inside one moves to just after it.
 * Returns null when the edit left every token intact.
 */
export function repairTokens(previous: string, next: string, refs: ReadonlySet<string>): { value: string; caret: number } | null {
    const tokens = findTokens(previous, refs);

    if (tokens.length === 0) {
        return null;
    }

    let prefix = 0;
    while (prefix < previous.length && prefix < next.length && previous[prefix] === next[prefix]) {
        prefix++;
    }

    let suffix = 0;
    while (
        suffix < previous.length - prefix &&
        suffix < next.length - prefix &&
        previous[previous.length - 1 - suffix] === next[next.length - 1 - suffix]
    ) {
        suffix++;
    }

    const removedEnd = previous.length - suffix;
    const inserted = next.slice(prefix, next.length - suffix);

    if (prefix === removedEnd) {
        const host = tokens.find((token) => token.start < prefix && prefix < token.end);

        return host
            ? { value: previous.slice(0, host.end) + inserted + previous.slice(host.end), caret: host.end + inserted.length }
            : null;
    }

    let start = prefix;
    let end = removedEnd;
    let clipped = false;

    for (const token of tokens) {
        if (token.start < end && token.end > start && (token.start < start || token.end > end)) {
            start = Math.min(start, token.start);
            end = Math.max(end, token.end);
            clipped = true;
        }
    }

    return clipped ? { value: previous.slice(0, start) + inserted + previous.slice(end), caret: start + inserted.length } : null;
}
