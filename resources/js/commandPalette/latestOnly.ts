/** Resolves a call's value only while it is the newest call; an older call resolves null, even when it rejects. */
export function latestOnly<T>(): (promise: Promise<T>) => Promise<T | null> {
    let newest = 0;

    return async (promise) => {
        const mine = ++newest;
        try {
            const value = await promise;

            return mine === newest ? value : null;
        } catch (failure) {
            if (mine === newest) {
                throw failure;
            }

            return null;
        }
    };
}
