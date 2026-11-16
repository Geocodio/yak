/** True on Apple platforms, where the modifier key is Command. */
export function isMacPlatform(): boolean {
    return typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);
}
