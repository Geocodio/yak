export const PICKS_STORAGE_KEY = 'yak:palette:picks';
const MAXIMUM_IDS = 200;

type PickStorage = Pick<Storage, 'getItem' | 'setItem'>;
/** Pick counts, least recently picked first. */
type Picks = [id: string, count: number][];

function readPicks(storage: PickStorage | undefined): Picks {
    try {
        const parsed: unknown = JSON.parse(storage?.getItem(PICKS_STORAGE_KEY) ?? '[]');

        return Array.isArray(parsed) ? (parsed as Picks) : [];
    } catch {
        return [];
    }
}

/** Remembers which actions and results the user runs. Storage that is missing or throws means no memory. */
export function createRankingMemory(storage: PickStorage | undefined) {
    return {
        /** Under 1, so ranking only reorders matches inside one score tier. */
        boostFor(id: string): number {
            const count = readPicks(storage).find(([pickedId]) => pickedId === id)?.[1] ?? 0;

            return count / (count + 1);
        },
        recordPick(id: string): void {
            try {
                const picks = readPicks(storage);
                const count = picks.find(([pickedId]) => pickedId === id)?.[1] ?? 0;
                const next = [...picks.filter(([pickedId]) => pickedId !== id), [id, count + 1]].slice(-MAXIMUM_IDS);
                storage?.setItem(PICKS_STORAGE_KEY, JSON.stringify(next));
            } catch {
                // Storage is unavailable, so there is nothing to remember.
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

export const boostFor = (id: string): number => createRankingMemory(browserStorage()).boostFor(id);
export const recordPick = (id: string): void => createRankingMemory(browserStorage()).recordPick(id);
