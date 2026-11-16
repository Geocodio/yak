/** Non-null when the first word is one of the keywords and more words follow; the rest is the prompt. */
export function splitNewThreadQuery(query: string, keywords: string[]): { prompt: string } | null {
    const [firstWord, ...rest] = query.trim().split(/\s+/);

    if (rest.length === 0 || !keywords.includes(firstWord.toLowerCase())) {
        return null;
    }

    return { prompt: rest.join(' ') };
}
