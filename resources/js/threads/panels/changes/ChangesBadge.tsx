import type { ThreadData } from '@/types/threads';
import { useThreadDiff } from './useThreadDiff';

/** The number of changed files, hidden at zero. */
export function ChangesBadge({ thread }: { thread: ThreadData }) {
    const { diff } = useThreadDiff(thread, null);

    if (diff === null || diff.filesCount === 0) {
        return null;
    }

    return <>{diff.filesCount}</>;
}
