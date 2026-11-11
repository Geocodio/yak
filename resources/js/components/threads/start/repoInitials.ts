/** First letters of the first two path segments; a single segment gives its first two letters; empty gives ''. */
export function repoInitials(slug: string): string {
    const segments = slug.split('/').filter(Boolean);
    const letters = segments.length >= 2 ? segments.slice(0, 2).map((segment) => segment[0]).join('') : (segments[0] ?? '').slice(0, 2);

    return letters.toUpperCase();
}
