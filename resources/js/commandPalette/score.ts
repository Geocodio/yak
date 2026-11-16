type Scorable = { label: string; keywords: string[] };

const MAXIMUM_BOOST = 0.99;

function isSubsequence(word: string, text: string): boolean {
    let position = 0;
    for (const letter of text) {
        if (letter === word[position]) {
            position += 1;
        }
    }

    return position === word.length;
}

function scoreWord(word: string, label: string, keywords: string[]): number {
    if (label.startsWith(word)) {
        return 4;
    }

    const words = [label, ...keywords].flatMap((text) => text.split(/\s+/));
    if (words.some((candidateWord) => candidateWord.startsWith(word))) {
        return 3;
    }

    if ([label, ...keywords].some((text) => text.includes(word))) {
        return 2;
    }

    // Letters in order are matched against the label only: across keywords they would match nearly everything.
    return isSubsequence(word, label) ? 1 : 0;
}

/**
 * Scores a candidate against a query, 0 meaning no match. Each query word earns
 * a tier (4 label prefix, 3 word prefix in label or keywords, 2 substring,
 * 1 letters in order) and every word must match.
 */
export function scoreAction(query: string, candidate: Scorable): number {
    const words = query.toLowerCase().split(/\s+/).filter(Boolean);
    const label = candidate.label.toLowerCase();
    const keywords = candidate.keywords.map((keyword) => keyword.toLowerCase());
    let total = 0;

    for (const word of words) {
        const tier = scoreWord(word, label, keywords);
        if (tier === 0) {
            return 0;
        }
        total += tier;
    }

    return total;
}

/**
 * Matches only, best first, stable for ties. The boost adds less than one point,
 * so it reorders candidates within a tier but never across tiers.
 */
export function rankActions<T extends { id: string; label: string; keywords: string[] }>(
    query: string,
    candidates: T[],
    boost: (id: string) => number = () => 0,
): T[] {
    if (query.trim() === '') {
        return [...candidates];
    }

    return candidates
        .map((candidate) => ({ candidate, score: scoreAction(query, candidate) }))
        .filter(({ score }) => score > 0)
        .map(({ candidate, score }) => ({
            candidate,
            score: score + Math.min(Math.max(boost(candidate.id), 0), MAXIMUM_BOOST),
        }))
        .sort((left, right) => right.score - left.score)
        .map(({ candidate }) => candidate);
}
