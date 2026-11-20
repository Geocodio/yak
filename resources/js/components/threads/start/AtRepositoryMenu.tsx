import { cn } from '@geocodio/console-ui';
import { RepositoryAvatar } from '@/components/threads/RepositoryAvatar';

/** The `@query` the caret sits in: it starts the text or follows whitespace, and holds no whitespace itself. */
export function findAtMention(text: string, caret: number): { start: number; query: string } | null {
    const match = /(?:^|\s)@([^\s@]*)$/.exec(text.slice(0, caret));

    return match ? { start: caret - match[1].length - 1, query: match[1] } : null;
}

/** Where the text mentions a repository as `@owner/name`, for each slug (case-insensitive) in `slugs`. */
export function findRepositoryMentions(text: string, slugs: string[]): { start: number; end: number }[] {
    const wanted = new Set(slugs.map((slug) => slug.toLowerCase()));

    return [...text.matchAll(/(?<=^|\s)@([\w./-]*[\w-])(?![\w-])/g)]
        .filter((match) => wanted.has(match[1].toLowerCase()))
        .map((match) => ({ start: match.index, end: match.index + match[0].length }));
}

/** Repositories whose slug contains the typed query, case-insensitively. */
export function filterRepositories(slugs: string[], query: string): string[] {
    const needle = query.toLowerCase();

    return slugs.filter((slug) => slug.toLowerCase().includes(needle));
}

/**
 * The list that opens under the description when `@` is typed. Focus stays in
 * the textarea, which drives `activeIndex` with the arrow keys, so options use
 * `onMouseDown` to keep the caret where it is.
 */
export function AtRepositoryMenu({ slugs, activeIndex, onPick }: { slugs: string[]; activeIndex: number; onPick: (slug: string) => void }) {
    return (
        <div
            role="listbox"
            aria-label="Repositories"
            className="absolute left-14 top-14 z-20 max-h-44 w-[min(320px,calc(100%-72px))] overflow-y-auto rounded-xl border border-hair-strong bg-panel p-1 shadow-lg"
            data-testid="at-repo-menu"
        >
            {slugs.length === 0 ? (
                <p className="px-2.5 py-2 text-[12.5px] text-muted">No matching repository</p>
            ) : (
                slugs.map((slug, index) => (
                    <div
                        key={slug}
                        role="option"
                        aria-selected={index === activeIndex}
                        onMouseDown={(event) => {
                            event.preventDefault();
                            onPick(slug);
                        }}
                        className={cn('flex cursor-pointer items-center gap-2 rounded-lg px-2.5 py-1.5 text-[13px] text-body', index === activeIndex && 'bg-panel-2')}
                        data-testid="at-repo-option"
                    >
                        <RepositoryAvatar repo={slug} variant="owner" />
                        {slug}
                    </div>
                ))
            )}
        </div>
    );
}
