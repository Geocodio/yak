/** The question to show: the chosen one, or the last one when a newer round has fewer questions. */
export function visibleStep(step: number, count: number): number {
    return Math.max(0, Math.min(step, count - 1));
}
