import type { ThreadDiffSummary } from '@/types/thread-changes';

/** The number of changed files, hidden at zero. */
export function ChangesBadge({ diff }: { diff?: ThreadDiffSummary | null }) {
    if (diff === null || diff === undefined || diff.filesCount === 0) {
        return null;
    }

    return <>{diff.filesCount}</>;
}
