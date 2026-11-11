import type { ThreadData } from '@/types/threads';
import { useStack } from './useStack';

/** Awake over total sub-threads, e.g. `2/3`. Hidden until the stack has loaded or has no sub-threads. */
export function StackBadge({ thread }: { thread: ThreadData }) {
    const { stack } = useStack(thread);

    if (stack === null || stack.children.length === 0) {
        return null;
    }

    return <>{stack.limit.awake}/{stack.children.length}</>;
}
