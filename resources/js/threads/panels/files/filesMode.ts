import type { ThreadDiffSummary } from '@/types/thread-changes';

/** The two ways into the Files view: the changed files with their diffs, or the whole repository and sandbox with the editor. */
export type FilesMode = 'changed' | 'everything';

/** A request to open one file, raised by a link elsewhere on the page; each request has its own id so the same file can be asked for twice. */
export type FocusRequest = { path: string; id: number };

/**
 * The mode the Files view shows: the one asked for, else Changed when the thread
 * has changes and Everything when it has none. `null` while the diff is loading.
 */
export function resolveFilesMode(requested: FilesMode | null, diff: Pick<ThreadDiffSummary, 'filesCount' | 'throwawayFilesCount'> | null, isDiffLoading: boolean): FilesMode | null {
    if (requested !== null) {
        return requested;
    }

    if (diff === null) {
        return isDiffLoading ? null : 'everything';
    }

    return diff.filesCount + diff.throwawayFilesCount > 0 ? 'changed' : 'everything';
}
