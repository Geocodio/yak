import { Button, TextInput, toast } from '@geocodio/console-ui';
import { Folder, Loader2, Lock, Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import repos from '@/routes/repos';
import type { GitHubSearchRepo } from '@/types/repositories';

/**
 * Best matching Sentry project slug for a repo name, or null when nothing
 * looks like a good fit. Mirrors `RepoForm::guessSentryProject()`: percentage
 * similarity (PHP's `similar_text`) against both the slug and the display
 * name, 60%+ required so we never preselect a random project.
 */
function guessSentryProject(repoName: string, sentryProjects: { value: string; label: string }[]): string | null {
    if (sentryProjects.length === 0) {
        return null;
    }

    const needle = repoName.toLowerCase();
    let bestValue: string | null = null;
    let bestScore = 0;

    for (const project of sentryProjects) {
        for (const candidate of [project.value.toLowerCase(), project.label.toLowerCase()]) {
            const score = similarity(needle, candidate);
            if (score > bestScore) {
                bestScore = score;
                bestValue = project.value;
            }
        }
    }

    return bestScore >= 60 ? bestValue : null;
}

/** Percentage of matching characters between two strings (longest common substring, recursive) -- a JS approximation of PHP's similar_text. */
function similarity(a: string, b: string): number {
    const matched = similarText(a, b);
    return a.length + b.length === 0 ? 0 : (matched * 2 * 100) / (a.length + b.length);
}

function similarText(a: string, b: string): number {
    if (a === '' || b === '') {
        return 0;
    }

    let max = 0;
    let posA = 0;
    let posB = 0;

    for (let i = 0; i < a.length; i++) {
        for (let j = 0; j < b.length; j++) {
            let k = 0;
            while (i + k < a.length && j + k < b.length && a[i + k] === b[j + k]) {
                k++;
            }
            if (k > max) {
                max = k;
                posA = i;
                posB = j;
            }
        }
    }

    if (max === 0) {
        return 0;
    }

    let sum = max;
    sum += similarText(a.slice(0, posA), b.slice(0, posB));
    sum += similarText(a.slice(posA + max), b.slice(posB + max));

    return sum;
}

export function GitHubRepoPicker({
    selected,
    sentryProjects,
    onSelect,
    onClear,
    onCiDetected,
}: {
    selected: { fullName: string; defaultBranch: string } | null;
    sentryProjects: { value: string; label: string }[];
    onSelect: (repo: GitHubSearchRepo, guessedSentryProject: string | null) => void;
    onClear: () => void;
    /** Called once `repos.github-detect` resolves after a repo is picked. */
    onCiDetected?: (ciSystem: string) => void;
}) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<GitHubSearchRepo[]>([]);
    const [searching, setSearching] = useState(false);
    const [searchError, setSearchError] = useState<string | null>(null);
    const [open, setOpen] = useState(false);
    const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(() => {
        if (debounceRef.current) {
            clearTimeout(debounceRef.current);
        }

        debounceRef.current = setTimeout(() => {
            setSearching(true);

            fetch(repos.githubSearch.url({ query: { q: query } }), {
                headers: { Accept: 'application/json' },
            })
                .then((response) => {
                    if (!response.ok) {
                        throw new Error(`Search failed (${response.status}).`);
                    }

                    return response.json();
                })
                .then((data: { repos: GitHubSearchRepo[]; error: string | null }) => {
                    setResults(data.repos ?? []);
                    setSearchError(data.error ?? null);
                })
                .catch(() => {
                    // Reporting a failed search as "no matches" reads as "that
                    // repository does not exist", which sends people looking in
                    // the wrong place. Say which one it is.
                    setResults([]);
                    setSearchError('Could not reach GitHub. Check the GitHub App credentials on the health page, then try again.');
                })
                .finally(() => setSearching(false));
        }, 300);

        return () => {
            if (debounceRef.current) {
                clearTimeout(debounceRef.current);
            }
        };
    }, [query]);

    if (selected) {
        return (
            <div className="flex items-center justify-between rounded-card border border-hair bg-panel-2 px-4 py-3">
                <div className="flex items-center gap-3">
                    <Folder size={16} className="text-muted" />
                    <div>
                        <p className="text-[13px] font-medium">{selected.fullName}</p>
                        <p className="text-[12px] text-muted">{selected.defaultBranch} branch</p>
                    </div>
                </div>
                <Button onClick={onClear}>Change</Button>
            </div>
        );
    }

    return (
        <div className="relative">
            <TextInput
                placeholder="Search your GitHub repositories..."
                className="pl-8 pr-8"
                value={query}
                onChange={(event) => setQuery(event.target.value)}
                onFocus={() => setOpen(true)}
                onBlur={() => setTimeout(() => setOpen(false), 150)}
                data-testid="github-repo-search"
            />
            <Search size={14} className="pointer-events-none absolute top-1/2 left-2.5 -translate-y-1/2 text-faint" />
            {searching && (
                <Loader2
                    size={14}
                    className="pointer-events-none absolute top-1/2 right-2.5 -translate-y-1/2 animate-spin text-faint"
                    data-testid="github-search-spinner"
                />
            )}
            {open && searchError !== null && (
                <div className="absolute z-20 mt-1 w-full rounded-card border border-hair bg-panel px-4 py-3 shadow-card">
                    <p className="text-[12px] text-fail" data-testid="github-search-error">
                        {searchError}
                    </p>
                </div>
            )}
            {open && searchError === null && !searching && query !== '' && results.length === 0 && (
                <div className="absolute z-20 mt-1 w-full rounded-card border border-hair bg-panel px-4 py-3 shadow-card">
                    <p className="text-[12px] text-muted" data-testid="github-search-empty">
                        No repositories match &ldquo;{query}&rdquo;.
                    </p>
                </div>
            )}
            {open && searchError === null && results.length > 0 && (
                <div className="absolute z-20 mt-1 w-full rounded-card border border-hair bg-panel shadow-card">
                    <ul className="max-h-64 overflow-y-auto py-1">
                        {results.map((repo) => (
                            <li key={repo.fullName}>
                                <button
                                    type="button"
                                    className="flex w-full items-center justify-between gap-3 px-4 py-2.5 text-left hover:bg-panel-2"
                                    onMouseDown={(event) => {
                                        event.preventDefault();
                                        onSelect(repo, guessSentryProject(repo.name, sentryProjects));
                                        setQuery('');
                                        setOpen(false);

                                        if (onCiDetected) {
                                            fetch(repos.githubDetect.url({ query: { full_name: repo.fullName } }), {
                                                headers: { Accept: 'application/json' },
                                            })
                                                .then((response) => response.json())
                                                .then((data: { ciSystem: string }) => onCiDetected(data.ciSystem))
                                                .catch(() => {
                                                    // Detection is a convenience, so this is not fatal --
                                                    // but silently leaving the CI field on its default
                                                    // looks like Yak inspected the repo and decided.
                                                    toast.info('Could not detect the CI system for this repository. Pick it below.');
                                                });
                                        }
                                    }}
                                >
                                    <div className="flex min-w-0 items-center gap-2">
                                        <span className="truncate text-[13px] font-medium">{repo.name}</span>
                                        {repo.private && <Lock size={12} className="shrink-0 text-faint" />}
                                        {repo.language && <span className="shrink-0 text-[11px] text-muted">{repo.language}</span>}
                                    </div>
                                </button>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}
