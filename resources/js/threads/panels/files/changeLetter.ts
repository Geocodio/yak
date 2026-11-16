export type ChangedPaths = { changedPaths: string[]; addedPaths: string[] };

export function changeLetterFor(path: string, changed: ChangedPaths): 'A' | 'M' | null {
    if (changed.addedPaths.includes(path)) {
        return 'A';
    }

    return changed.changedPaths.includes(path) ? 'M' : null;
}
