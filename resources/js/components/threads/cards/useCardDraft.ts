import { useCallback, useState, type SetStateAction } from 'react';

const drafts = new Map<string, unknown>();

/**
 * State of a card that survives the card being mounted somewhere else, as when a rotation moves it between the
 * conversation and the area above the composer. Without a key it is plain state.
 */
export function useCardDraft<T>(key: string | undefined, initial: T): [T, (next: SetStateAction<T>) => void] {
    const [value, setValue] = useState<T>(() => (key !== undefined && drafts.has(key) ? (drafts.get(key) as T) : initial));
    const set = useCallback(
        (next: SetStateAction<T>) =>
            setValue((previous) => {
                const resolved = typeof next === 'function' ? (next as (previous: T) => T)(previous) : next;

                if (key !== undefined) {
                    drafts.set(key, resolved);
                }

                return resolved;
            }),
        [key],
    );

    return [value, set];
}

/** The key of one field of one card of one thread. */
export function cardDraftKey(threadId: string, cardKind: string, field: string): string {
    return `${threadId}:${cardKind}:${field}`;
}

/** Forgets the drafts of a thread's cards other than the one now shown, so a card that comes back starts empty. */
export function clearCardDrafts(threadId: string, keepCardKind: string | null): void {
    for (const key of [...drafts.keys()]) {
        if (key.startsWith(`${threadId}:`) && !key.startsWith(`${threadId}:${keepCardKind}:`)) {
            drafts.delete(key);
        }
    }
}
