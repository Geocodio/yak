export const PICKS_STORAGE_KEY = 'yak:palette:picks';
const MAXIMUM_IDS = 200;

type PickStorage = Pick<Storage, 'getItem' | 'setItem'>;
/** Pick counts, least recently picked first. */
type Picks = [id: string, count: number][];

function readPicks(storage: PickStorage | undefined): Picks {
    try {
        const parsed: unknown = JSON.parse(storage?.getItem(PICKS_STORAGE_KEY) ?? '[]');
        if (!Array.isArray(parsed)) {
            return [];
        }

        return parsed.filter((entry): entry is [string, number] => Array.isArray(entry) && typeof entry[0] === 'string' && typeof entry[1] === 'number' && Number.isFinite(entry[1]) && entry[1] > 0);
    } catch {
        return [];
    }
}

/**
 * Remembers which actions and results the user runs. Storage that is missing,
 * throws or holds malformed data means no memory. The stored map is read once
 * per memory and kept current by `recordPick`.
 */
export function createRankingMemory(storage: PickStorage | undefined) {
    let picks: Picks | null = null;
    const current = (): Picks => (picks ??= readPicks(storage));

    return {
        /** Under 1, so ranking only reorders matches inside one score tier. */
        boostFor(id: string): number {
            const count = current().find(([pickedId]) => pickedId === id)?.[1] ?? 0;

            return count / (count + 1);
        },
        recordPick(id: string): void {
            const existing = current();
            const count = existing.find(([pickedId]) => pickedId === id)?.[1] ?? 0;
            const next = [...existing.filter(([pickedId]) => pickedId !== id), [id, count + 1] as [string, number]].slice(-MAXIMUM_IDS);
            try {
                storage?.setItem(PICKS_STORAGE_KEY, JSON.stringify(next));
                picks = next;
            } catch {
                // Storage is unavailable, so nothing is remembered.
            }
        },
    };
}

/** Rows the user picked before come first, most picked first; the rest keep their order. */
export function pickedFirst<T extends { id: string }>(rows: T[], boostFor: (id: string) => number): T[] {
    return rows
        .map((row, index) => ({ row, index, boost: boostFor(row.id) }))
        .sort((left, right) => right.boost - left.boost || left.index - right.index)
        .map(({ row }) => row);
}

const browserStorage = (): Storage | undefined => {
    try {
        return localStorage;
    } catch {
        return undefined;
    }
};

let browserMemory: ReturnType<typeof createRankingMemory> | null = null;
const memory = () => (browserMemory ??= createRankingMemory(browserStorage()));

export const boostFor = (id: string): number => memory().boostFor(id);
export const recordPick = (id: string): void => memory().recordPick(id);
