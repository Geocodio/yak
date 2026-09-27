/**
 * Reload options for polling a page whose list is an infinite-scroll prop.
 * A poll fetches page 1 again; merging it with the `prepend` intent puts
 * page 1's rows first, in the server's order, ahead of the rows already
 * scrolled into, instead of appending a brand-new row after the last
 * loaded page. The server's `matchOn('data.id')` drops the stale copies.
 * The list must also keep `preserveUrl` on, so the URL, and with it the
 * page a poll fetches, stays on page 1 while the user scrolls.
 */
export function pollInfiniteScroll(only: string[]) {
    return { only, headers: { 'X-Inertia-Infinite-Scroll-Merge-Intent': 'prepend' } };
}
