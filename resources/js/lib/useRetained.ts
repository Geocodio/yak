const lastLoaded = new Map<string, unknown>();

/**
 * Deferred props vanish from the page props on every same-page response, then
 * arrive again with a second request. This returns the newest loaded value
 * for `key` while the prop is `undefined`, so what it feeds does not fall
 * back to a skeleton after each instant save. `null` is a loaded value.
 */
export function useRetained<T>(key: string, value: T | undefined): T | undefined {
    if (value !== undefined) {
        lastLoaded.set(key, value);
    }

    return value !== undefined ? value : (lastLoaded.get(key) as T | undefined);
}
