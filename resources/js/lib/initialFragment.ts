/** The address fragment the page was opened with, read before the router rewrites the address without it. */
export const initialFragment: string = typeof window === 'undefined' ? '' : window.location.hash.slice(1);
