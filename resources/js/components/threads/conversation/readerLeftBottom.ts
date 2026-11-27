export const NEAR_BOTTOM_PIXELS = 80;

/**
 * Whether a reader's input left the bottom: the view moved up, or it ended far from the bottom. Input that moved nothing (a rubber-band at the bottom, a key in a field) did not.
 */
export function readerLeftBottom({ scrollTopAtInput, scrollTopNow, distanceFromBottom }: { scrollTopAtInput: number; scrollTopNow: number; distanceFromBottom: number }): boolean {
    return scrollTopNow < scrollTopAtInput || distanceFromBottom >= NEAR_BOTTOM_PIXELS;
}
