/** The row ids in their new order after moving one row a step; unchanged at either end. */
export function moveRow(order: number[], rowId: number, step: -1 | 1): number[] {
    const index = order.indexOf(rowId);
    const target = index + step;

    if (index === -1 || target < 0 || target >= order.length) {
        return order;
    }

    const next = [...order];
    [next[index], next[target]] = [next[target], next[index]];

    return next;
}

/** "2 of 3 checks", or null when no check is known. */
export function checksLabel(passedChecks: number, totalChecks: number): string | null {
    return totalChecks === 0 ? null : `${passedChecks} of ${totalChecks} checks`;
}

export function durationLabel(seconds: number | null): string {
    if (seconds === null) {
        return '';
    }

    return seconds >= 60 ? `${Math.floor(seconds / 60)}m ${String(seconds % 60).padStart(2, '0')}s` : `${seconds}s`;
}
