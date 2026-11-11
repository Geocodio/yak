import { diffLines } from 'diff';

/** 1-based lines of `current` that were added or modified relative to `headContent`. A null head marks nothing. */
export function changedLines(headContent: string | null, current: string): number[] {
    if (headContent === null) {
        return [];
    }

    const lines: number[] = [];
    let line = 1;

    for (const part of diffLines(headContent, current)) {
        if (part.removed) {
            continue;
        }
        const count = part.count ?? 0;
        if (part.added) {
            for (let offset = 0; offset < count; offset++) {
                lines.push(line + offset);
            }
        }
        line += count;
    }

    return lines;
}
