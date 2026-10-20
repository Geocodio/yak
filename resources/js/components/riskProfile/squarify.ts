import type { RiskMapNode } from '@/types/riskProfile';

export type Rect = { x: number; y: number; width: number; height: number };

/**
 * Squarified treemap layout: lays items out in rows along the shorter side,
 * adding to a row while that keeps its worst aspect ratio from growing.
 */
export function squarify<T>(items: { value: number; item: T }[], rect: Rect): { item: T; rect: Rect }[] {
    const sorted = items.filter((entry) => entry.value > 0).sort((a, b) => b.value - a.value);
    const total = sorted.reduce((sum, entry) => sum + entry.value, 0);
    if (total <= 0 || rect.width <= 0 || rect.height <= 0) {
        return [];
    }
    const scale = (rect.width * rect.height) / total;
    const output: { item: T; rect: Rect }[] = [];
    let remaining = { ...rect };
    let row: { value: number; item: T }[] = [];

    const worst = (entries: typeof row, side: number): number => {
        const areas = entries.map((entry) => entry.value * scale);
        const sum = areas.reduce((a, b) => a + b, 0);
        return Math.max(...areas.map((area) => Math.max((side * side * area) / (sum * sum), (sum * sum) / (side * side * area))));
    };

    const placeRow = (entries: typeof row): void => {
        const sum = entries.reduce((a, entry) => a + entry.value * scale, 0);
        const isHorizontal = remaining.width >= remaining.height;
        const thickness = sum / (isHorizontal ? remaining.height : remaining.width);
        let offset = 0;
        for (const entry of entries) {
            const length = (entry.value * scale) / thickness;
            output.push({
                item: entry.item,
                rect: isHorizontal
                    ? { x: remaining.x, y: remaining.y + offset, width: thickness, height: length }
                    : { x: remaining.x + offset, y: remaining.y, width: length, height: thickness },
            });
            offset += length;
        }
        remaining = isHorizontal
            ? { x: remaining.x + thickness, y: remaining.y, width: remaining.width - thickness, height: remaining.height }
            : { x: remaining.x, y: remaining.y + thickness, width: remaining.width, height: remaining.height - thickness };
    };

    for (const entry of sorted) {
        const side = Math.min(remaining.width, remaining.height);
        if (row.length === 0 || worst([...row, entry], side) <= worst(row, side)) {
            row.push(entry);
        } else {
            placeRow(row);
            row = [entry];
        }
    }
    if (row.length > 0) {
        placeRow(row);
    }

    return output;
}

/**
 * The deepest node that holds a file. A "files" node holds the loose files of
 * exactly its directory; directory and "other" nodes hold their directory and
 * everything below it. The longest matching path wins, and a deeper node wins a tie.
 */
export function leafFor(path: string, tree: RiskMapNode[]): RiskMapNode | null {
    const directory = path.includes('/') ? path.slice(0, path.lastIndexOf('/')) : '';
    let best: { node: RiskMapNode; score: number; depth: number } | null = null;

    const visit = (nodes: RiskMapNode[], depth: number): void => {
        for (const node of nodes) {
            for (const candidate of node.paths) {
                const isExact = directory === candidate;
                const isBelow = candidate !== '' && directory.startsWith(`${candidate}/`);
                if (node.kind === 'files' ? !isExact : !isExact && !isBelow) {
                    continue;
                }
                const score = candidate.length * 2 + (node.kind === 'files' ? 1 : 0);
                if (best === null || score > best.score || (score === best.score && depth > best.depth)) {
                    best = { node, score, depth };
                }
            }
            visit(node.children, depth + 1);
        }
    };
    visit(tree, 0);

    return (best as { node: RiskMapNode } | null)?.node ?? null;
}
