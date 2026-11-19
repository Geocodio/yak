/** A duration in whole seconds as `42s`, `14m` or `1h 5m`. */
export function formatSeconds(seconds: number): string {
    if (seconds < 60) {
        return `${seconds}s`;
    }
    const minutes = Math.round(seconds / 60);

    return minutes < 60 ? `${minutes}m` : `${Math.floor(minutes / 60)}h ${minutes % 60}m`;
}
