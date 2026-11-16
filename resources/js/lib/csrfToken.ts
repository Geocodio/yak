/** The XSRF token Laravel keeps in a cookie, for plain `fetch` calls that return JSON or no content. */
export function csrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}
