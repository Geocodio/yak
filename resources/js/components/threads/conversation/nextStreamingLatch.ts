/**
 * Whether the follow keeps its ease through the gaps between streamed blocks: on once text streams in a running turn,
 * off when the turn ends or the thread changes.
 */
export function nextStreamingLatch({ previous, threadChanged, working, anyStreaming }: { previous: boolean; threadChanged: boolean; working: boolean; anyStreaming: boolean }): boolean {
    return working && (anyStreaming || (previous && !threadChanged));
}
