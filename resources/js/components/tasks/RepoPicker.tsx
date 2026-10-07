import { cn } from '@geocodio/console-ui';
import { Check, ChevronDown, GitBranch, Star } from 'lucide-react';
import { useEffect, useId, useRef, useState, type KeyboardEvent } from 'react';

/**
 * Subsequence match of `query` against `text`. Returns the matched character
 * positions and a score that prefers consecutive runs and word starts, or
 * null when a query character has no match left to the right of the last.
 */
export function fuzzyMatch(query: string, text: string): { score: number; positions: number[] } | null {
    const needle = query.toLowerCase();
    const haystack = text.toLowerCase();
    const positions: number[] = [];
    let cursor = 0;
    let score = 0;

    for (const character of needle) {
        const found = haystack.indexOf(character, cursor);
        if (found === -1) {
            return null;
        }
        const isRun = found === cursor && positions.length > 0;
        const isWordStart = found === 0 || haystack[found - 1] === '-' || haystack[found - 1] === '/';
        score += (isRun ? 3 : 1) + (isWordStart ? 2 : 0);
        positions.push(found);
        cursor = found + 1;
    }

    return { score: score - (haystack.length - needle.length) * 0.05, positions };
}

function Highlighted({ text, positions }: { text: string; positions: number[] }) {
    const marked = new Set(positions);
    return (
        <>
            {[...text].map((character, index) =>
                marked.has(index) ? (
                    <span key={index} className="font-semibold text-accent-text">{character}</span>
                ) : (
                    character
                ),
            )}
        </>
    );
}

function initials(slug: string): string {
    return slug
        .split(/[-/]/)
        .map((part) => part[0] ?? '')
        .join('')
        .slice(0, 2)
        .toUpperCase();
}

/**
 * A typeahead over the active repositories that also opens as a plain
 * dropdown from its chevron. The list is not portalled: it lives inside the
 * dialog, which keeps overflow visible, so no positioning library is needed.
 */
export function RepoPicker({
    options,
    defaultOption,
    value,
    onChange,
    onClose,
    autoFocus,
    className,
}: {
    options: string[];
    /** The default repository, starred in the list. */
    defaultOption?: string | null;
    value: string | null;
    onChange: (value: string | null) => void;
    /** Fires when the picker gives up focus, whether or not a repository was chosen. */
    onClose?: () => void;
    autoFocus?: boolean;
    className?: string;
}) {
    const listId = useId();
    const inputRef = useRef<HTMLInputElement>(null);
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState(value ?? '');
    const [active, setActive] = useState(0);

    useEffect(() => {
        if (!open) {
            setQuery(value ?? '');
        }
    }, [value, open]);

    useEffect(() => {
        if (autoFocus) {
            inputRef.current?.focus();
        }
    }, [autoFocus]);

    const isFiltering = query.trim() !== '' && query !== value;
    const matches = isFiltering
        ? options
              .map((slug) => ({ slug, match: fuzzyMatch(query.trim(), slug) }))
              .filter((entry): entry is { slug: string; match: NonNullable<ReturnType<typeof fuzzyMatch>> } => entry.match !== null)
              .sort((a, b) => b.match.score - a.match.score)
        : options.map((slug) => ({ slug, match: { score: 0, positions: [] as number[] } }));
    const activeIndex = Math.min(active, Math.max(matches.length - 1, 0));

    const pick = (slug: string) => {
        onChange(slug);
        setQuery(slug);
        setOpen(false);
    };

    const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            if (!open) {
                setOpen(true);
                setActive(Math.max(0, options.indexOf(value ?? '')));
            } else {
                setActive((activeIndex + 1) % Math.max(matches.length, 1));
            }
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActive((activeIndex - 1 + matches.length) % Math.max(matches.length, 1));
        } else if (event.key === 'Enter') {
            if (open && matches[activeIndex]) {
                event.preventDefault();
                pick(matches[activeIndex].slug);
            }
        } else if (event.key === 'Escape' && open) {
            event.preventDefault();
            event.stopPropagation();
            setOpen(false);
            setQuery(value ?? '');
        }
    };

    return (
        <div className={cn('relative', className)} data-testid="repo-picker">
            <div
                className={cn(
                    'flex h-8 items-center gap-1.5 rounded-control border border-hair-strong bg-panel pl-2.5 pr-1 shadow-card',
                    'transition-[box-shadow,border-color] focus-within:border-accent focus-within:shadow-[0_0_0_3px_var(--accent-soft)]',
                )}
            >
                <GitBranch size={13} className="shrink-0 text-faint" aria-hidden="true" />
                <input
                    ref={inputRef}
                    role="combobox"
                    aria-expanded={open}
                    aria-controls={listId}
                    aria-autocomplete="list"
                    autoComplete="off"
                    spellCheck={false}
                    placeholder="Search or pick a repository…"
                    value={query}
                    onChange={(event) => {
                        setQuery(event.target.value);
                        setActive(0);
                        setOpen(true);
                        if (value !== null && event.target.value !== value) {
                            onChange(null);
                        }
                    }}
                    onFocus={() => setOpen(true)}
                    onBlur={() => {
                        setOpen(false);
                        setQuery(value ?? '');
                        onClose?.();
                    }}
                    onKeyDown={onKeyDown}
                    className="h-full min-w-0 flex-1 bg-transparent text-[13px] text-body outline-none placeholder:text-faint"
                    data-testid="repo-picker-input"
                />
                <button
                    type="button"
                    tabIndex={-1}
                    aria-label="Browse repositories"
                    onMouseDown={(event) => {
                        event.preventDefault();
                        if (open) {
                            inputRef.current?.blur();
                        } else {
                            setQuery('');
                            inputRef.current?.focus();
                        }
                    }}
                    className="grid size-6 shrink-0 place-items-center rounded-chip text-faint hover:bg-panel-2 hover:text-body"
                    data-testid="repo-picker-toggle"
                >
                    <ChevronDown size={14} className={cn('transition-transform', open && 'rotate-180')} aria-hidden="true" />
                </button>
            </div>

            {open && (
                <div
                    id={listId}
                    role="listbox"
                    className="ui-floating absolute left-0 top-[calc(100%+6px)] z-10 max-h-60 w-[max(100%,280px)] overflow-auto rounded-card border border-hair bg-panel p-1 shadow-overlay"
                    data-testid="repo-picker-list"
                >
                    {matches.length === 0 ? (
                        <div className="px-2.5 py-2 text-[12px] text-faint">No repository matches “{query.trim()}”</div>
                    ) : (
                        matches.map(({ slug, match }, index) => (
                            <div
                                key={slug}
                                role="option"
                                aria-selected={index === activeIndex}
                                onMouseDown={(event) => {
                                    event.preventDefault();
                                    pick(slug);
                                }}
                                onMouseMove={() => setActive(index)}
                                className={cn(
                                    'flex cursor-default items-center gap-2.5 rounded-chip px-2 py-1.5 text-[13px] text-body',
                                    index === activeIndex && 'bg-accent-soft',
                                )}
                                data-testid={`repo-option-${slug}`}
                            >
                                <span className="grid size-5 shrink-0 place-items-center rounded-[5px] bg-brand font-mono text-[10px] font-semibold text-accent-ink">
                                    {initials(slug)}
                                </span>
                                <span className="truncate font-medium">
                                    <Highlighted text={slug} positions={match.positions} />
                                </span>
                                {slug === defaultOption && (
                                    <Star
                                        size={13}
                                        className="ml-auto shrink-0 fill-current text-faint"
                                        aria-label="Default repository"
                                        data-testid="repo-option-default"
                                    />
                                )}
                                {slug === value && (
                                    <Check
                                        size={14}
                                        className={cn('shrink-0 text-accent-text', slug !== defaultOption && 'ml-auto')}
                                        aria-hidden="true"
                                    />
                                )}
                            </div>
                        ))
                    )}
                </div>
            )}
        </div>
    );
}
