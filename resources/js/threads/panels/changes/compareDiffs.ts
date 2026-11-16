import type { ThreadDiffFile, ThreadDiffSummary } from '@/types/thread-changes';

export type FileMark = 'new' | 'updated';

/**
 * Which files of a fresh diff are new or changed since the diff shown before.
 * A first load or a refetch of the same head marks nothing; a changed file is
 * one whose added or deleted line count moved.
 */
export function compareDiffs(previous: Pick<ThreadDiffSummary, 'headSha' | 'files'> | null, next: Pick<ThreadDiffSummary, 'headSha' | 'files'>, keyOf: (file: ThreadDiffFile) => string): Map<string, FileMark> {
    const marks = new Map<string, FileMark>();
    if (previous === null || previous.headSha === next.headSha) {
        return marks;
    }
    const before = new Map(previous.files.map((file) => [keyOf(file), file]));
    for (const file of next.files) {
        const old = before.get(keyOf(file));
        if (old === undefined) {
            marks.set(keyOf(file), 'new');
        } else if (old.additions !== file.additions || old.deletions !== file.deletions) {
            marks.set(keyOf(file), 'updated');
        }
    }

    return marks;
}
