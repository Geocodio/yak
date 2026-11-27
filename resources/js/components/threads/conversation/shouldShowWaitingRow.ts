export const WAITING_ROW_DELAY_MS = 400;

/**
 * Whether the thinking row may show now. A row that would come back right after a streamed block ended waits, so the
 * next block can arrive without the row popping in and collapsing again.
 */
export function shouldShowWaitingRow({ wantsRow, lastStreamEndedAt, now }: { wantsRow: boolean; lastStreamEndedAt: number | null; now: number }): boolean {
    return wantsRow && (lastStreamEndedAt === null || now - lastStreamEndedAt >= WAITING_ROW_DELAY_MS);
}
