/**
 * The next scroll position while easing toward `target`: closes `1 - exp(-10 * elapsedSeconds)` of the gap, at least one pixel, never past the target.
 */
export function followStep(current: number, target: number, elapsedSeconds: number): number {
    return Math.min(target, current + Math.max(1, (target - current) * (1 - Math.exp(-10 * elapsedSeconds))));
}
