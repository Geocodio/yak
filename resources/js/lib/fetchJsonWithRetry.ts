/**
 * GETs a JSON endpoint and retries when the network drops the request or the server answers 5xx.
 * A 4xx answer is final (a hidden thread's 404 must not be retried into existence), and an
 * aborted request stops at once.
 */
export async function fetchJsonWithRetry<T>(
    url: string,
    options: { signal?: AbortSignal; retryDelaysMs?: number[]; fetcher?: typeof fetch; wait?: (milliseconds: number) => Promise<void> } = {},
): Promise<T> {
    const { signal, retryDelaysMs = [500, 1500], fetcher = fetch, wait = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds)) } = options;

    for (let attempt = 0; ; attempt++) {
        let response: Response | null = null;

        try {
            response = await fetcher(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal });
        } catch (failure) {
            if (signal?.aborted || attempt >= retryDelaysMs.length) {
                throw failure;
            }
        }

        if (response !== null) {
            if (response.ok) {
                return (await response.json()) as T;
            }

            if (response.status < 500 || attempt >= retryDelaysMs.length) {
                throw new Error(`Request failed (${response.status})`);
            }
        }

        await wait(retryDelaysMs[attempt]);

        if (signal?.aborted) {
            throw new DOMException('Aborted', 'AbortError');
        }
    }
}
