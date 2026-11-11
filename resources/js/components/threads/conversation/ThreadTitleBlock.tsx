/** Opens the conversation with the thread's name and where its work lands. */
export function ThreadTitleBlock({ title, repo, branch }: { title: string; repo: string; branch: string | null }) {
    return (
        <div data-testid="thread-title-block">
            <h2 className="text-[18px] font-semibold text-body">{title}</h2>
            <p className="mt-0.5 font-mono text-[12px] text-muted">{branch ? `${repo} on ${branch}` : repo}</p>
        </div>
    );
}
